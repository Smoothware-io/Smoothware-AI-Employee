<?php

namespace Database\Factories;

use App\Enums\PromptRuleSetStatus;
use App\Models\PromptRuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptRuleSet>
 */
class PromptRuleSetFactory extends Factory
{
    protected $model = PromptRuleSet::class;

    public function definition(): array
    {
        return [
            'version' => fn (): int => (int) (PromptRuleSet::max('version') ?? 0) + 1,
            'status' => PromptRuleSetStatus::Draft,
        ];
    }
}
