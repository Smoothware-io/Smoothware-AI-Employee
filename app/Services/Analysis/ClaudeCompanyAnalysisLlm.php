<?php

namespace App\Services\Analysis;

use App\Contracts\CompanyAnalysisLlm;
use App\Enums\AnalysisPriority;
use App\Models\Company;
use App\Support\Analysis\AnalysisResult;
use App\Support\Analysis\WebsiteSignals;
use Illuminate\Support\Facades\Http;

/**
 * Production analysis LLM — Anthropic Claude (Opus 4.8) via the Messages API with
 * structured outputs. Given the website signals + retrieved KB (our services) so
 * recommendations stay grounded in what Smoothware actually offers.
 *
 * Not exercised in tests/CI (no API key); the Fake covers those. Wired when
 * ANALYSIS_LLM_DRIVER=claude.
 */
class ClaudeCompanyAnalysisLlm implements CompanyAnalysisLlm
{
    public function __construct(
        private string $apiKey,
        private string $model = 'claude-opus-4-8',
        private string $effort = 'high',
    ) {}

    public function analyze(Company $company, WebsiteSignals $signals, array $chunks, array $rules): AnalysisResult
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 3072,
            'thinking' => ['type' => 'adaptive'],
            'output_config' => [
                'effort' => $this->effort,
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
            'system' => $this->systemPrompt($signals, $chunks, $rules),
            'messages' => [[
                'role' => 'user',
                'content' => "Assess {$company->name} ({$company->domain}). Return marketing findings, KB-grounded recommendations, an inferred priority, and an overall confidence.",
            ]],
        ])->throw()->json();

        return $this->parse($response);
    }

    /**
     * @param  array<int, array{id: int, content: string}>  $chunks
     * @param  array<int, string>  $rules
     */
    private function systemPrompt(WebsiteSignals $signals, array $chunks, array $rules): string
    {
        $services = $chunks === []
            ? '(no services retrieved)'
            : collect($chunks)->map(fn (array $c): string => "- {$c['content']}")->implode("\n");
        $signalsJson = json_encode([
            'pagespeed' => $signals->pagespeed, 'mobile' => $signals->mobileScore, 'seo' => $signals->seoScore,
            'ssl' => $signals->ssl, 'cms' => $signals->cms, 'analytics' => $signals->analytics, 'tracking' => $signals->tracking,
        ]);

        return <<<PROMPT
        You analyse a prospect's website for Smoothware, a web & software agency, to
        help a sales rep. Base recommendations ONLY on Smoothware's services below —
        do not recommend things we don't offer.

        SMOOTHWARE SERVICES (from the knowledge base):
        {$services}

        OBJECTIVE WEBSITE SIGNALS (already measured — do not restate as findings):
        {$signalsJson}

        Produce: marketing findings (CTA, branding, conversion, social media),
        recommendations mapped to our services, an inferred priority (high/medium/
        low) for pursuing this prospect, and an overall confidence (0-1). Give a
        confidence per finding.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $finding = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'key' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'assessment' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['key', 'label', 'assessment', 'confidence'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'marketing' => ['type' => 'array', 'items' => $finding],
                'recommendations' => ['type' => 'array', 'items' => $finding],
                'inferred_priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'overall_confidence' => ['type' => 'number'],
            ],
            'required' => ['marketing', 'recommendations', 'inferred_priority', 'overall_confidence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function parse(array $response): AnalysisResult
    {
        $text = collect($response['content'] ?? [])->firstWhere('type', 'text')['text'] ?? '{}';
        $data = json_decode($text, true) ?: [];

        return new AnalysisResult(
            marketing: $data['marketing'] ?? [],
            recommendations: $data['recommendations'] ?? [],
            inferredPriority: AnalysisPriority::tryFrom($data['inferred_priority'] ?? ''),
            overallConfidence: (float) ($data['overall_confidence'] ?? 0.0),
            inputTokens: (int) ($response['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($response['usage']['output_tokens'] ?? 0),
        );
    }
}
