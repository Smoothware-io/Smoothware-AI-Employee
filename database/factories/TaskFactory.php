<?php

namespace Database\Factories;

use App\Enums\RecordSource;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Company;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => fake()->randomElement(TaskType::cases()),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'status' => TaskStatus::Open,
            'due_at' => now()->addDays(fake()->numberBetween(1, 14)),
            'source' => RecordSource::Manual,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => now()->subDays(2)]);
    }
}
