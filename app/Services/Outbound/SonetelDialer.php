<?php

namespace App\Services\Outbound;

use App\Models\Call;
use App\Models\Company;
use App\Services\EventLogger;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Places an outbound call by bridging Sonetel to OpenAI Realtime (Phase 6).
 *
 * The mechanism, because it is not obvious: OpenAI has no phone line and can only
 * RECEIVE SIP. So we do not ask OpenAI to dial. We ask Sonetel to bridge two
 * legs — leg 1 is OpenAI's SIP endpoint (an incoming call from OpenAI's point of
 * view), leg 2 is the prospect — and Sonetel connects them. The AI is on one end,
 * the person on the other, and Sonetel is the carrier in the middle.
 *
 * Sonetel rings leg 1 FIRST, so the AI answers into silence while leg 2 is still
 * dialling. The session instructions tell it to wait for a human voice; without
 * that it greets an empty line and the prospect joins mid-sentence.
 *
 * Every dial passes {@see OutboundGate} first. There is no path around it.
 */
class SonetelDialer
{
    public function __construct(
        private OutboundGate $gate,
        private EventLogger $events,
    ) {}

    /**
     * @throws RuntimeException when a gate refuses — loudly, with the reason,
     *                          because a silent no-op here looks like a bug and
     *                          gets "fixed" by removing the check.
     */
    public function call(string $phone, ?Company $company = null, ?string $objective = null): Call
    {
        $blockers = $this->gate->blockers(
            phone: $phone,
            email: $company?->email,
            domain: $company?->domain,
        );

        if ($blockers !== []) {
            throw new RuntimeException('Refusing to dial: '.implode(' | ', $blockers));
        }

        $call = Call::create([
            'company_id' => $company?->getKey(),
            'direction' => 'outbound',
            'status' => 'dialing',
            'to_number' => $phone,
            'from_number' => config('outbound.sonetel.caller_id'),
            'started_at' => now(),
        ]);

        $response = Http::withToken((string) config('outbound.sonetel.token'))
            ->asJson()
            ->timeout(20)
            ->post((string) config('outbound.sonetel.callback_url'), [
                // Leg 1: the AI. Answered before leg 2 is even dialled.
                'call1' => $this->openAiSipUri(),
                // Leg 2: the person.
                'call2' => $phone,
                // What the person sees ringing.
                'show2' => config('outbound.sonetel.caller_id'),
            ]);

        if ($response->failed()) {
            $call->forceFill([
                'status' => 'failed',
                'ended_at' => now(),
            ])->save();

            throw new RuntimeException('Sonetel refused the call: '.$response->status().' '.mb_substr($response->body(), 0, 200));
        }

        // Reference logging: the number itself never enters the append-only log.
        $this->events->log(
            action: 'call.dialed',
            entity: $call,
            payload: [
                'objective' => $objective,
                'gates_passed' => true,
                'disclosure_configured' => filled(config('outbound.disclosure')),
            ],
            companyId: $company?->getKey(),
        );

        $call->forceFill([
            'external_id' => $response->json('call_id') ?? $response->json('response.call_id'),
        ])->save();

        return $call;
    }

    /** sip:PROJECT_ID@sip.api.openai.com;transport=tls */
    private function openAiSipUri(): string
    {
        return sprintf(
            'sip:%s@%s;transport=tls',
            config('outbound.openai.project_id'),
            config('outbound.openai.sip_host'),
        );
    }
}
