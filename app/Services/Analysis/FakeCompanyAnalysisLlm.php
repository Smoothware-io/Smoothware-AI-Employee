<?php

namespace App\Services\Analysis;

use App\Contracts\CompanyAnalysisLlm;
use App\Enums\AnalysisPriority;
use App\Models\Company;
use App\Support\Analysis\AnalysisResult;
use App\Support\Analysis\WebsiteSignals;

/**
 * Deterministic, offline stand-in for Claude (dev/tests/CI). Derives a plausible
 * marketing assessment, KB-grounded recommendations, and an inferred priority
 * from the website signals — so disagreement detection and provenance are
 * testable without an API key.
 */
class FakeCompanyAnalysisLlm implements CompanyAnalysisLlm
{
    public function analyze(Company $company, WebsiteSignals $signals, array $chunks, array $rules): AnalysisResult
    {
        $marketing = [
            ['key' => 'cta', 'label' => 'Call to action', 'assessment' => $signals->pagespeed < 60 ? 'Weak/unclear CTA above the fold' : 'Clear primary CTA', 'confidence' => 0.6],
            ['key' => 'branding', 'label' => 'Branding', 'assessment' => 'Consistent visual identity', 'confidence' => 0.5],
            ['key' => 'conversion', 'label' => 'Conversion', 'assessment' => $signals->mobileScore < 50 ? 'Mobile UX likely hurts conversion' : 'Reasonable conversion path', 'confidence' => 0.55],
            ['key' => 'social', 'label' => 'Social media', 'assessment' => $signals->tracking ? 'Tracking suggests active social presence' : 'Limited social signals', 'confidence' => 0.4],
        ];

        $recommendations = [];
        if ($signals->pagespeed < 70) {
            $recommendations[] = ['key' => 'website', 'label' => 'Website', 'assessment' => 'Rebuild/optimise for speed (Smoothware web development)', 'confidence' => 0.8];
        }
        if ($signals->seoScore < 60) {
            $recommendations[] = ['key' => 'seo', 'label' => 'SEO', 'assessment' => 'Technical + content SEO engagement', 'confidence' => 0.75];
        }
        $recommendations[] = ['key' => 'ai_chatbot', 'label' => 'AI chatbot', 'assessment' => 'Add an AI receptionist/chatbot for inbound handling', 'confidence' => 0.6];
        if (! $signals->ssl || $signals->cms === null) {
            $recommendations[] = ['key' => 'hosting', 'label' => 'Hosting', 'assessment' => 'Managed hosting + SSL + maintenance', 'confidence' => 0.7];
        }

        $score = ($signals->pagespeed + $signals->mobileScore + $signals->seoScore) / 3;
        $priority = match (true) {
            $score < 50 => AnalysisPriority::High,
            $score < 70 => AnalysisPriority::Medium,
            default => AnalysisPriority::Low,
        };

        return new AnalysisResult(
            marketing: $marketing,
            recommendations: $recommendations,
            inferredPriority: $priority,
            overallConfidence: round(0.6 + ($chunks !== [] ? 0.1 : 0.0), 2),
            inputTokens: str_word_count(implode(' ', array_column($chunks, 'content'))),
            outputTokens: 60,
        );
    }
}
