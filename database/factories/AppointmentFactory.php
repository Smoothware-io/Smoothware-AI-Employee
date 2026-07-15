<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\RecordSource;
use App\Models\Appointment;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 21));

        return [
            'company_id' => Company::factory(),
            'title' => fake()->sentence(3),
            'starts_at' => $start,
            'ends_at' => $start->clone()->addHour(),
            'location' => fake()->randomElement(['Google Meet', 'Client office', 'Phone']),
            'status' => AppointmentStatus::Scheduled,
            'source' => RecordSource::Manual,
        ];
    }
}
