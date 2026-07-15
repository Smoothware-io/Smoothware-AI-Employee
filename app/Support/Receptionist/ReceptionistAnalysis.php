<?php

namespace App\Support\Receptionist;

use App\Enums\CallIntent;

/**
 * The structured result of the AI analysing a call transcript. Everything here
 * is a *proposal* — it becomes draft ai_actions for human review, never a
 * committed record (shadow mode).
 */
class ReceptionistAnalysis
{
    /**
     * @param  array<int, int>  $usedChunkIds  knowledge-chunk ids the AI cited
     */
    public function __construct(
        public readonly CallIntent $intent,
        public readonly string $summary,
        public readonly ?string $answer,       // grounded answer to the caller, or null
        public readonly array $usedChunkIds,
        public readonly ?string $companyName = null,
        public readonly ?string $contactFirstName = null,
        public readonly ?string $contactLastName = null,
        public readonly ?string $proposedTaskTitle = null,
        public readonly float $confidence = 0.0,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}
}
