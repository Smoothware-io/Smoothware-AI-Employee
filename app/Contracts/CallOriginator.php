<?php

namespace App\Contracts;

/**
 * Asks the telephony layer to place a call and bridge the prospect to the AI.
 *
 * Separate from {@see TelephonyProvider}, which parses inbound webhooks. This is
 * the outbound direction, and it is a different vendor relationship entirely:
 * Sonetel carries the audio, but it cannot address OpenAI (their SIP user part
 * must be a DID; OpenAI's must be the project id — mutually exclusive). Asterisk
 * exists to translate between the two, and this interface is how the CRM asks it.
 *
 * The Fake is what the whole test suite runs against, because a test that can
 * place a real call to a real stranger is not a test.
 */
interface CallOriginator
{
    /**
     * Place a call to $phone and bridge it to the AI when the person answers.
     *
     * Fire-and-forget by design: this returns once the request is ACCEPTED, not
     * once anyone has spoken. Whether the phone was answered arrives later, over
     * the OpenAI webhook. A method that blocked until "connected" would be lying
     * about what telephony can promise — the SonetelDialer already made that
     * mistake by reporting a queued 202 as "Calling…".
     *
     * @param  string  $phone  E.164, e.g. +31612345678
     * @param  string  $callerId  the number the person sees; belongs to the rep
     *                            accountable for the call
     * @return string a provider reference for correlating this request with the
     *                call that follows
     *
     * @throws \RuntimeException when the request is refused. Loudly — a silent
     *                           no-op looks like a bug and gets "fixed" by
     *                           deleting the check that caused it.
     */
    public function originate(string $phone, string $callerId): string;

    public function name(): string;
}
