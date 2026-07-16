<?php

namespace Database\Factories;

use App\Enums\AiActionStatus;
use App\Models\AiAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAction>
 *
 * Note: production code must transition status only via AiActionService, which
 * validates and audits every move. This factory sets status directly because
 * tests need to ARRIVE at a state cheaply rather than re-play the lifecycle to
 * reach it — that lifecycle has its own tests.
 */
class AiActionFactory extends Factory
{
    protected $model = AiAction::class;

    public function definition(): array
    {
        return [
            'action_type' => 'receptionist_intake',
            'status' => AiActionStatus::Draft,
            'proposed_payload' => ['company' => ['name' => fake()->company()]],
            'confidence_score' => fake()->randomFloat(3, 0.5, 0.99),
            'source_context_version' => 'rules:v1|kb:2026-07-16',
            'model_id' => 'claude-opus-4-8',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => AiActionStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => AiActionStatus::Rejected,
            'reviewed_at' => now(),
        ]);
    }
}
