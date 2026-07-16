<?php

namespace App\Services\Outbound;

use App\Models\Call;
use App\Models\Company;
use App\Models\SonetelAccount;
use App\Models\User;
use App\Services\EventLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Places an outbound call by bridging Sonetel to OpenAI Realtime (Phase 6).
 *
 * The mechanism, because it is not obvious: OpenAI has no phone line and can only
 * RECEIVE SIP. So we never ask OpenAI to dial. We ask Sonetel to bridge two legs —
 * leg 1 (`call1`) is OpenAI's SIP endpoint, leg 2 (`call2`) is the prospect — and
 * Sonetel connects them. Sonetel's own SDK documents that `call1` accepts a SIP
 * address, which is what makes this possible at all.
 *
 * Sonetel rings leg 1 FIRST, so the AI answers into silence while leg 2 is still
 * dialling. The session instructions tell it to wait for a human voice; without
 * that it greets an empty line and the prospect joins mid-sentence.
 *
 * Endpoint details verified against github.com/Sonetel/sonetel-python, not
 * guessed — see SonetelTokenService for why that mattered.
 *
 * Every dial passes {@see OutboundGate} first. There is no path around it.
 */
class SonetelDialer
{
    /** NOT api.sonetel.com — that host only serves the auth endpoint. */
    private const CALLBACK_URL = 'https://public-api.sonetel.com/make-calls/call/call-back';

    public function __construct(
        private OutboundGate $gate,
        private SonetelTokenService $tokens,
        private EventLogger $events,
    ) {}

    /**
     * @param  User|null  $as  whose Sonetel account places the call; defaults to
     *                         the authenticated rep. The caller ID a prospect sees
     *                         should belong to whoever is accountable for the call.
     *
     * @throws RuntimeException when a gate refuses — loudly, with the reason,
     *                          because a silent no-op looks like a bug and gets
     *                          "fixed" by removing the check.
     */
    public function call(string $phone, ?Company $company = null, ?string $objective = null, ?User $as = null): Call
    {
        $user = $as ?? Auth::user() ?? $company?->owner;

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Refusing to dial: no user to call as. A call must be attributable to a person.'
            );
        }

        $account = SonetelAccount::firstWhere('user_id', $user->getKey());
        $token = $this->tokens->tokenFor($user);

        if ($account === null || $token === null) {
            throw new RuntimeException(
                "Refusing to dial: {$user->name} has not connected a Sonetel account, or it needs reconnecting."
            );
        }

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
            'from_number' => $account->sonetel_number,
            'objective' => $objective,
            'handled_by' => $user->getKey(),
            'external_provider' => 'sonetel',
            'started_at' => now(),
        ]);

        $response = Http::withToken($token)
            ->asJson()
            ->timeout(20)
            ->post(self::CALLBACK_URL, [
                // Sonetel's SDK always sends an app_id; it identifies the caller
                // application in their logs.
                'app_id' => 'smoothware-ai-employee',
                // Leg 1: the AI. Answered before leg 2 is even dialled.
                'call1' => $this->openAiSipUri(),
                // Leg 2: the person.
                'call2' => $phone,
                // NOTE: show_1 / show_2, with underscores. Sonetel recommends
                // 'automatic' — it picks the best number from the account — so a
                // configured caller ID is an override, not a requirement.
                'show_1' => 'automatic',
                'show_2' => $account->sonetel_number ?: 'automatic',
            ]);

        if ($response->failed()) {
            $call->forceFill(['status' => 'failed', 'ended_at' => now()])->save();

            throw new RuntimeException(
                'Sonetel refused the call: '.$response->status().' '.mb_substr($response->body(), 0, 200)
            );
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
            'external_id' => $response->json('call_id')
                ?? $response->json('response.call_id')
                ?? $response->json('data.call_id'),
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
