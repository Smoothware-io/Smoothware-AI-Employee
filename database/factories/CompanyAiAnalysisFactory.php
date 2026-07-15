<?php

namespace Database\Factories;

use App\Enums\AnalysisPriority;
use App\Enums\RecordSource;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyAiAnalysis>
 */
class CompanyAiAnalysisFactory extends Factory
{
    protected $model = CompanyAiAnalysis::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'technical' => [['key' => 'pagespeed', 'label' => 'PageSpeed', 'assessment' => 'Score 70/100', 'confidence' => 0.9]],
            'marketing' => [['key' => 'cta', 'label' => 'Call to action', 'assessment' => 'Clear CTA', 'confidence' => 0.6]],
            'recommendations' => [['key' => 'seo', 'label' => 'SEO', 'assessment' => 'Engage SEO', 'confidence' => 0.75]],
            'inferred_priority' => AnalysisPriority::Medium,
            'overall_confidence' => 0.7,
            'generated_at' => now(),
            'source' => RecordSource::Ai,
        ];
    }
}
