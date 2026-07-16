<?php

namespace Database\Factories;

use App\Enums\AssigneeStrategy;
use App\Enums\FollowUpTrigger;
use App\Models\FollowUpRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUpRule>
 */
class FollowUpRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Call back after an inbound call',
            'description' => null,
            'trigger' => FollowUpTrigger::CallLogged,
            'conditions' => null,
            'delay_minutes' => 60 * 24,
            'task_type' => 'follow_up',
            'task_title' => 'Follow up with {company.name}',
            'task_description' => null,
            'assignee_strategy' => AssigneeStrategy::CompanyOwner,
            'assignee_id' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function trigger(FollowUpTrigger $trigger): static
    {
        return $this->state(fn () => ['trigger' => $trigger]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
