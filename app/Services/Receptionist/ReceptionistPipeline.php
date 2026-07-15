<?php

namespace App\Services\Receptionist;

use App\Contracts\ReceptionistLlm;
use App\Enums\ActorType;
use App\Models\AiAction;
use App\Models\AiRun;
use App\Models\Call;
use App\Services\AiActionService;
use App\Services\ContextVersion;
use App\Services\EventLogger;
use App\Services\KnowledgeRetriever;
use App\Services\PromptRuleSetService;

/**
 * The AI receptionist orchestration (Phase 3, shadow mode). Given a call
 * transcript it:
 *   1. retrieves grounding KB chunks (Phase 2),
 *   2. runs the LLM on ONLY those chunks + the active prompt rules,
 *   3. ENFORCES grounding — below-threshold retrieval or uncited/foreign
 *      citations => fallback_to_human; the AI never improvises an answer,
 *   4. records an AiRun (ops metrics; no PII),
 *   5. proposes ONE draft ai_action (a "receptionist_intake" bundle) for a
 *      human to approve — nothing is auto-created.
 *
 * The transcript-derived PII lives only in the draft's proposed_payload (an
 * erasable table), never in the AiRun ops record or the append-only event log.
 */
class ReceptionistPipeline
{
    public function __construct(
        private KnowledgeRetriever $retriever,
        private ReceptionistLlm $llm,
        private CompanyMatcher $matcher,
        private PromptRuleSetService $rules,
        private ContextVersion $contextVersion,
        private AiActionService $actions,
        private EventLogger $events,
    ) {}

    public function process(Call $call, string $transcript): AiRun
    {
        $topK = (int) config('receptionist.grounding.top_k', 5);
        $minScore = (float) config('receptionist.grounding.min_score', 0.15);

        $retrieved = $this->retriever->retrieve($transcript, $topK);
        $topScore = $retrieved[0]['score'] ?? 0.0;
        $chunks = array_map(
            fn (array $r): array => ['id' => (int) $r['chunk']->id, 'content' => (string) $r['chunk']->content],
            $retrieved,
        );
        $chunkIds = array_column($chunks, 'id');

        $startedAt = microtime(true);
        $analysis = $this->llm->analyze($transcript, $chunks, $this->activeRuleTexts());
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        // --- Grounding enforcement (system-level, not just prompt) ---
        $cited = array_values(array_intersect($analysis->usedChunkIds, $chunkIds));
        $citationsValid = count($cited) === count($analysis->usedChunkIds);
        $grounded = $topScore >= $minScore
            && $analysis->answer !== null
            && $cited !== []
            && $citationsValid;
        $fallbackToHuman = ! $grounded;

        $contextVersion = $this->contextVersion->current();

        $run = AiRun::create([
            'kind' => 'receptionist',
            'model_id' => $this->modelLabel(),
            'context_version' => $contextVersion,
            'subject_type' => $call->getMorphClass(),
            'subject_id' => $call->getKey(),
            'grounded' => $grounded,
            'fallback_to_human' => $fallbackToHuman,
            'retrieved_chunk_ids' => $chunkIds,
            'latency_ms' => $latencyMs,
            'input_tokens' => $analysis->inputTokens,
            'output_tokens' => $analysis->outputTokens,
            // Ops metrics only — no personal data.
            'meta' => [
                'intent' => $analysis->intent->value,
                'top_score' => round((float) $topScore, 4),
                'citations_valid' => $citationsValid,
                'confidence' => $analysis->confidence,
            ],
        ]);

        // The detected intent is an AI annotation on the factual call record.
        $call->forceFill(['intent' => $analysis->intent])->saveQuietly();

        $this->proposeDraft($call, $run, $analysis, $grounded, $contextVersion);

        $this->events->log(
            action: 'receptionist.analysed',
            entity: $run,
            payload: ['grounded' => $grounded, 'fallback_to_human' => $fallbackToHuman],
            actorType: ActorType::AiAgent,
            companyId: $call->company_id,
        );

        return $run;
    }

    private function proposeDraft(Call $call, AiRun $run, $analysis, bool $grounded, string $contextVersion): AiAction
    {
        $match = $this->matcher->match($analysis->companyName, $call->from_number, null);
        $matchMinConfidence = (float) config('receptionist.match.min_confidence', 0.6);
        $useMatch = $match !== null && $match['confidence'] >= $matchMinConfidence;

        $noteBody = $grounded
            ? "Inbound call summary: {$analysis->summary}\n\nGrounded answer given: {$analysis->answer}"
            : "Inbound call summary: {$analysis->summary}\n\n⚠ The AI could not ground an answer in the knowledge base — a human should follow up.";

        $payload = [
            'call_id' => $call->getKey(),
            'intent' => $analysis->intent->value,
            'grounded' => $grounded,
            'company' => [
                'match_id' => $useMatch ? $match['company']->id : null,
                'match_confidence' => $useMatch ? $match['confidence'] : null,
                'name' => $useMatch ? $match['company']->name : $analysis->companyName,
                'phone' => $call->from_number,
            ],
            'contact' => $analysis->contactFirstName || $analysis->contactLastName ? [
                'first_name' => $analysis->contactFirstName,
                'last_name' => $analysis->contactLastName,
                'phone' => $call->from_number,
            ] : null,
            'note' => [
                'category' => 'follow_up',
                'body' => $noteBody,
            ],
            'task' => ($analysis->proposedTaskTitle || ! $grounded) ? [
                'type' => 'follow_up',
                'title' => $analysis->proposedTaskTitle ?: 'Human follow-up: caller question not grounded',
            ] : null,
        ];

        return $this->actions->propose('receptionist_intake', $payload, [
            'confidence_score' => $analysis->confidence,
            'source_context_version' => $contextVersion,
            'model_id' => $this->modelLabel(),
            'ai_run_id' => $run->uuid,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function activeRuleTexts(): array
    {
        $set = $this->rules->active();

        return $set ? $set->rules->pluck('rule_text')->all() : [];
    }

    private function modelLabel(): string
    {
        return config('receptionist.drivers.llm') === 'claude'
            ? (string) config('services.anthropic.model', 'claude-opus-4-8')
            : 'fake-receptionist';
    }
}
