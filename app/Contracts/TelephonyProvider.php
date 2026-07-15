<?php

namespace App\Contracts;

use App\Support\Receptionist\InboundCallData;

/**
 * A telephony vendor (Sonetel in production). Isolates provider-specific webhook
 * payloads and APIs so the receptionist pipeline is vendor-agnostic. Outbound
 * calling (Phase 6) will extend this interface.
 */
interface TelephonyProvider
{
    /**
     * Map a raw inbound-call webhook payload into normalised call data.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseInboundWebhook(array $payload): InboundCallData;

    public function name(): string;
}
