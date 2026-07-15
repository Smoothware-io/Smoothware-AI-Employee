<?php

namespace Database\Factories;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\RecordSource;
use App\Models\Call;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'direction' => CallDirection::Inbound,
            'status' => CallStatus::Completed,
            'from_number' => fake()->e164PhoneNumber(),
            'to_number' => fake()->e164PhoneNumber(),
            'started_at' => now()->subMinutes(10),
            'ended_at' => now()->subMinutes(5),
            'duration_seconds' => 300,
            'source' => RecordSource::Manual,
        ];
    }

    /** A call carrying recording + transcript content (Phase 3 shape). */
    public function withContent(): static
    {
        return $this->state(fn () => [
            'external_provider' => 'sonetel',
            'external_id' => 'son_'.fake()->uuid(),
            'recording_disk' => 'local',
            'recording_path' => 'recordings/'.fake()->uuid().'.mp3',
            'recording_bytes' => 1_024_000,
            'transcript' => 'Caller: I need a new website. Agent: happy to help.',
            'transcript_status' => 'done',
            'summary' => 'Prospect wants a new marketing website.',
            'retention_expires_at' => now()->addDays(90),
        ]);
    }
}
