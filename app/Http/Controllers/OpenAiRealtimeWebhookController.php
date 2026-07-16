<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Company;
use App\Services\EventLogger;
use App\Services\Outbound\CallInstructionBuilder;
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

        // Match the leg back to the call we placed, so the AI knows who it rang.
        $call = $this->matchCall($fromNumber, $toNumber);
        $company = $call?->company;

        $built = $this->instructions->forCompany($company, $call?->objective ?? null);

        $accepted = Http::withToken((string) config('outbound.openai.key'))
            ->asJson()
            ->timeout(10)
            ->post("https://api.openai.com/v1/realtime/calls/{$callId}/accept", [
                'type' => 'realtime',
                'model' => config('outbound.openai.model'),
                'instructions' => $built['instructions'],
                'audio' => [
                    'output' => ['voice' => config('outbound.openai.voice')],
                ],
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
