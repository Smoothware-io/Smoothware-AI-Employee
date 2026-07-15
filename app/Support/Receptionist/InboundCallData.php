<?php

namespace App\Support\Receptionist;

use App\Enums\CallDirection;
use Illuminate\Support\Carbon;

/**
 * Normalised inbound-call data produced by a TelephonyProvider from a raw
 * provider webhook. Provider-specific payload shapes are mapped into this once,
 * in the adapter, so the rest of the pipeline is vendor-agnostic.
 */
class InboundCallData
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $externalId,
        public readonly CallDirection $direction,
        public readonly ?string $fromNumber,
        public readonly ?string $toNumber,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $endedAt = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $transcript = null,
        public readonly ?string $recordingDisk = null,
        public readonly ?string $recordingPath = null,
        public readonly ?int $recordingBytes = null,
        public readonly ?bool $consentObtained = null,
        public readonly ?string $consentMethod = null,
    ) {}
}
