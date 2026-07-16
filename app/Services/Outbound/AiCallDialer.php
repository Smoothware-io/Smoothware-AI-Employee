<?php

namespace App\Services\Outbound;

use App\Contracts\CallOriginator;
use App\Models\Call;
use App\Models\Company;
use App\Models\SonetelAccount;
use App\Models\User;
use App\Services\EventLogger;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Places an outbound AI call (Phase 6). Replaces {@see SonetelDialer}, which is
 * kept only for human click-to-call.
 *
 * Why the mechanism changed: SonetelDialer bridged two legs via Sonetel's
 * callback API with `call1` set to OpenAI's SIP address. Sonetel confirmed that
 * `call1` accepts regular phone numbers only — external SIP URIs are supported
 * for incoming-call forwarding, which is a different feature. That path cannot
 * work, and it was never the code that was wrong: the assumption underneath it
 * was.
 *
 * So the audio path is now:
 *
 *   Asterisk --(Sonetel trunk)--> prospect
 *   Asterisk --(SIP/TLS)-------> OpenAI Realtime
 *   Asterisk bridges the two
 *
 * Asterisk is the only component that can address both: Sonetel demands the SIP
 * user part be a DID, OpenAI demands it be the project id, and no single address
 * satisfies both.
 *
 * The person is dialled FIRST and OpenAI joins on answer. Reversed, the AI opens
 * its mandatory Art. 50 disclosure to a ringing phone, and the human arrives
 * halfway through a sentence having never been told they are talking to a
 * machine. The order is a legal requirement wearing an implementation detail.
 */
class AiCallDialer
{
    public function __construct(
        private OutboundGate $gate,
        private CallOriginator $originator,
        private EventLogger $events,
    ) {}

    /**
     * @param  User|null  $as  whose Sonetel number the prospect sees; defaults to
     *                         the authenticated rep. A call must be attributable
     *                         to a person who can answer for it.
     *
     * @throws RuntimeException when a gate refuses — loudly, with the reason.
     */
    public function call(string $phone, ?Company $company = null, ?string $objective = null, ?User $as = null): Call
    {
        $user = $as ?? Auth::user() ?? $company?->owner;

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Refusing to dial: no user to call as. A call must be attributable to a person.'
            );
        }

        // Every gate, before anything else: enabled, suppression, disclosure,
        // register screening, test-number allow-list, daily cap. There is no path
        // around this and there must never be one.
        $blockers = $this->gate->blockers(
            phone: $phone,
            email: $company?->email,
            domain: $company?->domain,
        );

        if ($blockers !== []) {
            throw new RuntimeException('Refusing to dial: '.implode(' | ', $blockers));
        }

        $account = SonetelAccount::firstWhere('user_id', $user->getKey());
        $callerId = $account?->sonetel_number;

        if (! filled($callerId)) {
            throw new RuntimeException(
                "Refusing to dial: {$user->name} has no Sonetel number to call from. "
                .'A withheld caller ID on a sales call is both hostile and, for telemarketing, unlawful.'
            );
        }

        $call = Call::create([
            'company_id' => $company?->getKey(),
            'direction' => 'outbound',
            // NOT 'in_progress'. Asterisk has accepted a request to ring someone;
            // nobody has answered and the AI has not spoken. SonetelDialer showed
            // "Calling…" for a queued 202 and the rep believed it.
            'status' => 'dialing',
            'to_number' => $phone,
            'from_number' => $callerId,
            'objective' => $objective,
            'handled_by' => $user->getKey(),
            'external_provider' => $this->originator->name(),
            'started_at' => now(),
        ]);

        try {
            $actionId = $this->originator->originate($phone, $callerId);
        } catch (RuntimeException $e) {
            $call->forceFill(['status' => 'failed', 'ended_at' => now()])->save();

            throw $e;
        }

        // The AMI ActionID correlates this request with the call that follows.
        // OpenAI's webhook later overwrites external_id with the real call_id —
        // which is the field SonetelDialer never populated, because it read
        // 'call_id' from a response that returns 'session_id'.
        $call->forceFill(['external_id' => $actionId])->save();

        // Reference logging: the number never enters the append-only log.
        $this->events->log(
            action: 'call.dialed',
            entity: $call,
            payload: [
                'objective' => $objective,
                'originator' => $this->originator->name(),
                'gates_passed' => true,
                'disclosure_configured' => filled(config('outbound.disclosure')),
            ],
            companyId: $company?->getKey(),
        );

        return $call;
    }
}
