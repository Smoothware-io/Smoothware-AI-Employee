<?php

namespace App\Services;

use App\Models\KnowledgeChunk;
use Illuminate\Support\Carbon;

/**
 * Produces the `source_context_version` stamp that every AI call records (Phase
 * 3+), tying an AI action back to the exact ruleset + knowledge-base state that
 * produced it. Example: "rules:v3|kb:20260715142230".
 */
class ContextVersion
{
    public function __construct(private PromptRuleSetService $rules) {}

    public function current(): string
    {
        $set = $this->rules->active();
        $rulesPart = $set ? "rules:v{$set->version}" : 'rules:none';

        $latest = KnowledgeChunk::max('embedded_at');
        $kbPart = $latest ? 'kb:'.Carbon::parse($latest)->format('YmdHis') : 'kb:empty';

        return "{$rulesPart}|{$kbPart}";
    }
}
