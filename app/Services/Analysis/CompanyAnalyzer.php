<?php

namespace App\Services\Analysis;

use App\Contracts\CompanyAnalysisLlm;
use App\Contracts\WebsiteAnalyzer;
use App\Enums\ActorType;
use App\Enums\RecordSource;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use App\Services\ContextVersion;
use App\Services\EventLogger;
use App\Services\KnowledgeRetriever;
use App\Services\PromptRuleSetService;

/**
 * Generates a company's AI analysis (Phase 4): objective technical signals from
 * the website scan + a KB-grounded marketing/recommendation assessment from the
 * LLM. Writes a new `company_ai_analyses` row (regenerable history) with full
 * provenance and records an `AiRun`. Never touches the manual analysis.
 */
class CompanyAnalyzer
{
    public function __construct(
        private WebsiteAnalyzer $website,
        private CompanyAnalysisLlm $llm,
        private KnowledgeRetriever $retriever,
        private PromptRuleSetService $rules,
        private ContextVersion $contextVersion,
        private EventLogger $events,
    ) {}

    public function analyze(Company $company): CompanyAiAnalysis
    {
        $signals = $this->website->analyze($company->domain);
        $chunks = $this->groundingChunks($company);

        $startedAt = microtime(true);
        $result = $this->llm->analyze($company, $signals, $chunks, $this->activeRuleTexts());
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $contextVersion = $this->contextVersion->current();
        $grounded = $chunks !== []; // recommendations are grounded in our services

        $run = AiRun::create([
            'kind' => 'analysis',
            'model_id' => $this->modelLabel(),
            'context_version' => $contextVersion,
            'subject_type' => $company->getMorphClass(),
            'subject_id' => $company->getKey(),
            'grounded' => $grounded,
            'fallback_to_human' => false,
            'retrieved_chunk_ids' => array_column($chunks, 'id'),
            'latency_ms' => $latencyMs,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'meta' => [
                'inferred_priority' => $result->inferredPriority?->value,
                'overall_confidence' => $result->overallConfidence,
            ],
        ]);

        $analysis = $company->aiAnalyses()->create([
            'technical' => $signals->toFindings(),
            'marketing' => $result->marketing,
            'recommendations' => $result->recommendations,
            'inferred_priority' => $result->inferredPriority,
            'overall_confidence' => $result->overallConfidence,
            'source_context_version' => $contextVersion,
            'model_id' => $this->modelLabel(),
            'ai_run_id' => $run->uuid,
            'generated_at' => now(),
            'source' => RecordSource::Ai,
        ]);

        $this->events->log(
            action: 'company_analysis.generated',
            entity: $analysis,
            payload: ['overall_confidence' => $result->overallConfidence, 'grounded' => $grounded],
            actorType: ActorType::AiAgent,
            companyId: $company->getKey(),
        );

        return $analysis;
    }

    /**
     * @return array<int, array{id: int, content: string}>
     */
    private function groundingChunks(Company $company): array
    {
        $query = "services and recommendations for a {$company->industry} website: web development, SEO, hosting, AI chatbot";

        return array_map(
            fn (array $r): array => ['id' => (int) $r['chunk']->id, 'content' => (string) $r['chunk']->content],
            $this->retriever->retrieve($query, (int) config('receptionist.grounding.top_k', 5)),
        );
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
        return config('analysis.drivers.llm') === 'claude'
            ? (string) config('services.anthropic.model', 'claude-opus-4-8')
            : 'fake-analysis';
    }
}
