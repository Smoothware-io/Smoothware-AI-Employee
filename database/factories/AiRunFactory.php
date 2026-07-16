<?php

namespace Database\Factories;

use App\Models\AiRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRun>
 */
class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    public function definition(): array
    {
        return [
            'kind' => 'receptionist',
            'model_id' => 'claude-opus-4-8',
            'context_version' => 'rules:v1|kb:2026-07-16',
            'grounded' => true,
            'fallback_to_human' => false,
            'latency_ms' => fake()->numberBetween(400, 3000),
            'input_tokens' => fake()->numberBetween(500, 4000),
            'output_tokens' => fake()->numberBetween(50, 800),
            'cost' => fake()->randomFloat(5, 0.001, 0.05),
        ];
    }

    public function fellBackToHuman(): static
    {
        return $this->state(fn () => ['fallback_to_human' => true]);
    }

    public function analysis(): static
    {
        return $this->state(fn () => ['kind' => 'analysis']);
    }
}
