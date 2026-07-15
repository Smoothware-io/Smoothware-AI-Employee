<?php

namespace Database\Factories;

use App\Enums\AnalysisPriority;
use App\Models\Company;
use App\Models\CompanyManualAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyManualAnalysis>
 */
class CompanyManualAnalysisFactory extends Factory
{
    protected $model = CompanyManualAnalysis::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'pain_points' => fake()->sentence(),
            'opportunities' => fake()->sentence(),
            'notes' => fake()->paragraph(),
            'priority' => AnalysisPriority::Medium,
        ];
    }
}
