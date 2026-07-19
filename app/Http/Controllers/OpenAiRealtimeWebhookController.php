<?php

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Jobs\ObserveRealtimeCall;
use App\Models\Call;
use App\Models\Company;
use App\Services\EventLogger;
use App\Services\Outbound\CallInstructionBuilder;
use App\Services\Voice\VoiceGateway;
use App\Services\Voice\VoiceToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Where OpenAI Realtime asks us what the AI should be (Phase 6).
 *
 * The flow that is easy to get backwards: OpenAI does not have a greeting of its
 * own. When SIP arrives it fires `realtime.call.incoming` at THIS endpoint, and
 * nothing happens until we answer by POSTing to its accept endpoint with the
 * session config. If this endpoint is unreachable or wrong, the call simply is
 * not answered.
 *
 * So this is the seam where the CRM's knowledge becomes the AI's voice:
 * {@see CallInstructionBuilder} assembles the KB + the versioned prompt rules +
 * what we know about the company, and that becomes the instructions.
 *
 * Must be publicly reachable (ngrok in dev). Signature-verified, because an
 * unverified "incoming call" is a stranger spending your OpenAI credit and
 * putting words in your company's mouth.
 */
class OpenAiRealtimeWebhookController extends Controller
{
    public function __construct(
        private CallInstructionBuilder $instructions,
        private EventLogger $events,
        private VoiceToolRegistry $tools,
        private VoiceGateway $gateway,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            Log::warning('OpenAI realtime webhook: bad signature', ['ip' => $request->ip()]);

            return response('invalid signature', 401);
        }

        if ($request->input('type') !== 'realtime.call.incoming') {
            return response('ignored', 200); // not ours to handle
        }

        $callId = (string) $request->input('data.call_id');

        if ($callId === '') {
            return response('missing call_id', 422);
        }

        [$fromNumber, $toNumber] = $this->numbersFrom((array) $request->input('data.sip_headers', []));

        // Match the leg back to a call we placed. An INBOUND call has none — nobody
        // dialled it — so one is created, or the transcript has nowhere to live.
        $call = $this->matchCall($fromNumber, $toNumber)
            ?? $this->recordInboundCall($fromNumber, $toNumber);

        $company = $call?->company;

        $built = $this->instructions->forCompany(
            company: $company,
            objective: $call?->objective ?? null,
            direction: $call?->direction ?? CallDirection::Inbound,
            // The instructions must AGREE with the tools we declare below. Told it
            // can book while no gateway can execute that would be the worse lie.
            withTools: $this->gateway->configured(),
        );

        $accepted = Http::withToken((string) config('outbound.openai.key'))
            ->asJson()
            ->timeout(10)
            ->post("https://api.openai.com/v1/realtime/calls/{$callId}/accept", [
                'type' => 'realtime',
                'model' => config('outbound.openai.model'),
                'instructions' => $built['instructions'],
                'audio' => [
                    'input' => [
                        // Turn-taking. Without this OpenAI uses basic server VAD,
                        // which on a phone line cuts people off and — worse — can
                        // treat the tail of its OWN speech (echoed back through a
                        // speaker) as an interruption and loop.
                        //
                        // semantic_vad decides you are DONE talking from meaning,
                        // not just from a silence gap, so it does not jump on a
                        // mid-sentence pause and is far less fooled by echo.
                        //
                        //   eagerness: how fast it decides you have finished.
                        //     low    waits longest (~8s)  — most patient, most lag
                        //     medium ~4s (a good default for a sales call)
                        //     high   ~2s — snappy, but talks over slow speakers
                        'turn_detection' => [
                            'type' => 'semantic_vad',
                            'eagerness' => config('outbound.openai.vad_eagerness', 'medium'),
                            // Let the caller barge in and have the AI stop cleanly.
                            'interrupt_response' => true,
                            'create_response' => true,
                        ],
                        // Transcribe the CALLER too. Without this OpenAI transcribes
                        // only its own speech, and the stored transcript reads as a
                        // monologue — every line "AI:", none "CALLER:". Confirmed on a
                        // real call before this was added, and it makes the record
                        // useless for review, which is most of why we keep one.
                        //
                        // whisper-1 rather than gpt-4o-transcribe: the newer models
                        // have reported failures in realtime sessions where whisper-1
                        // is unaffected. Configurable so it can move without a deploy
                        // once that settles.
                        'transcription' => [
                            'model' => config('outbound.openai.transcription_model', 'whisper-1'),
                        ],
                    ],
                    'output' => ['voice' => config('outbound.openai.voice')],
                ],
                // The AI's hands. Declared ONLY when go-voice is configured to
                // execute them (ARCHITECTURE §15.6): a tool the gateway cannot run
                // leaves the model calling into a void, waiting on a result that
                // never comes. No gateway -> no tools -> the AI talks but does not
                // act, and the PHP observer runs instead.
                ...($this->gateway->configured()
                    ? ['tools' => $this->tools->schemas(), 'tool_choice' => 'auto']
                    : []),
            ]);

        if ($accepted->failed()) {
            Log::error('OpenAI refused our accept', [
                'call_id' => $callId,
                'status' => $accepted->status(),
                'body' => mb_substr($accepted->body(), 0, 300),
            ]);

            return response('accept failed', 502);
        }

        // Provenance: which KB + ruleset produced what the caller heard. A
        // recording without this is a quote nobody can trace.
        $call?->forceFill([
            'external_id' => $callId,
            'status' => 'in_progress',
        ])->save();

        $this->events->log(
            action: 'call.ai_accepted',
            entity: $call,
            payload: [
                'context_version' => $built['context_version'],
                'model' => config('outbound.openai.model'),
            ],
            companyId: $company?->getKey(),
        );

        // Who rides along on the live call. Exactly one:
        //   - go-voice (Go control gateway) when configured: executes tool calls
        //     AND captures the transcript. The AI has hands.
        //   - the PHP observer otherwise: transcript only, no actions. A clean
        //     fallback so a missing or undeployed gateway never kills a call.
        if ($this->gateway->configured()) {
            $this->gateway->handOff($callId, [
                'company_id' => $company?->getKey(),
                'context_version' => $built['context_version'],
            ]);
        } else {
            // Queued: the webhook must answer in milliseconds while the observer
            // holds a socket open for the whole conversation.
            ObserveRealtimeCall::dispatch($callId, $call?->getKey());
        }

        return response('ok', 200);
    }

    /**
     * OpenAI signs every webhook. Without a configured secret we REFUSE rather
     * than trust — an open endpoint here is someone else's AI on your number.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('outbound.openai.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        $signature = (string) $request->header('webhook-signature');

        if ($id === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        // Replay window: a signature valid forever is not a signature.
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $signed = "{$id}.{$timestamp}.".$request->getContent();
        $key = base64_decode((string) preg_replace('/^whsec_/', '', $secret), true) ?: $secret;
        $expected = base64_encode(hash_hmac('sha256', $signed, $key, true));

        // The header may carry several space-separated "v1,<sig>" values.
        foreach (explode(' ', $signature) as $candidate) {
            $value = str_contains($candidate, ',') ? explode(',', $candidate, 2)[1] : $candidate;

            if (hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Someone rang us and the AI is about to answer: that is a Call, and Phase 1
     * already knows what a Call is. Matching it to a company is left to the
     * existing receptionist pipeline — this only has SIP headers, and guessing a
     * company from a caller ID here would duplicate CompanyMatcher badly.
     */
    private function recordInboundCall(?string $from, ?string $to): Call
    {
        return Call::create([
            'direction' => 'inbound',
            'status' => 'in_progress',
            'from_number' => $this->numberFromSipUri($from),
            'to_number' => $this->numberFromSipUri($to),
            'external_provider' => 'openai-realtime',
            'started_at' => now(),
        ]);
    }

    /** "sip:+31612345678@host" -> "+31612345678" */
    private function numberFromSipUri(?string $uri): ?string
    {
        if (! filled($uri)) {
            return null;
        }

        preg_match('/sip:([^@;>]+)/i', (string) $uri, $matches);

        return $matches[1] ?? mb_substr((string) $uri, 0, 60);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function numbersFrom(array $sipHeaders): array
    {
        $find = function (string $name) use ($sipHeaders): ?string {
            foreach ($sipHeaders as $header) {
                if (strcasecmp((string) ($header['name'] ?? ''), $name) === 0) {
                    return (string) ($header['value'] ?? '');
                }
            }

            return null;
        };

        return [$find('From'), $find('To')];
    }

    /**
     * The dialler creates the Call row before Sonetel bridges, so the most recent
     * dialing/in-progress call is ours. Matching on the SIP From/To is unreliable
     * here: leg 1 is Sonetel calling OpenAI, so the numbers describe the bridge,
     * not the prospect.
     */
    private function matchCall(?string $from, ?string $to): ?Call
    {
        return Call::query()
            ->where('direction', 'outbound')
            ->whereIn('status', ['dialing', 'in_progress'])
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest('id')
            ->first();
    }
}
