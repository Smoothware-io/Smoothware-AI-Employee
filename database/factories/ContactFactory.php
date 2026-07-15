<?php

namespace Database\Factories;

use App\Enums\RecordSource;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'job_title' => fake()->jobTitle(),
            'is_decision_maker' => false,
            'email' => fake()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'source' => RecordSource::Manual,
        ];
    }

    public function decisionMaker(): static
    {
        return $this->state(fn () => ['is_decision_maker' => true]);
    }
}
