<?php

namespace Database\Factories;

use App\Enums\KnowledgeType;
use App\Enums\PublishStatus;
use App\Enums\RecordSource;
use App\Models\KnowledgeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeEntry>
 */
class KnowledgeEntryFactory extends Factory
{
    protected $model = KnowledgeEntry::class;

    public function definition(): array
    {
        return [
            'type' => KnowledgeType::Faq,
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => PublishStatus::Draft,
            'source' => RecordSource::Manual,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => PublishStatus::Published]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['last_verified_at' => now()]);
    }
}
