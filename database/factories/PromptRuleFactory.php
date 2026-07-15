<?php

namespace Database\Factories;

use App\Models\PromptRule;
use App\Models\PromptRuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptRule>
 */
class PromptRuleFactory extends Factory
{
    protected $model = PromptRule::class;

    public function definition(): array
    {
        return [
            'prompt_rule_set_id' => PromptRuleSet::factory(),
            'category' => fake()->randomElement(['pricing', 'meetings', 'honesty']),
            'rule_text' => fake()->sentence(),
            'sort_order' => 0,
        ];
    }
}
