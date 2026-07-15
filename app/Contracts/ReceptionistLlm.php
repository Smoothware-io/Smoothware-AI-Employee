<?php

namespace App\Contracts;

use App\Support\Receptionist\ReceptionistAnalysis;

/**
 * The LLM that analyses a call transcript (Claude in production). It is given
 * ONLY the retrieved knowledge-base chunks and the active prompt rules, and must
 * cite the chunks it used — the orchestrator validates those citations to
 * enforce grounding (see ReceptionistAnalyzer).
 */
interface ReceptionistLlm
{
    /**
     * @param  array<int, array{id: int, content: string}>  $chunks  retrieved KB chunks
     * @param  array<int, string>  $rules  active prompt rules
     */
    public function analyze(string $transcript, array $chunks, array $rules): ReceptionistAnalysis;
}
