<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Enums\RecordSource;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'domain' => fake()->domainName(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'city' => fake()->city(),
            'country' => 'NL',
            'industry' => fake()->randomElement(['SaaS', 'Retail', 'Hospitality', 'Legal', 'Healthcare']),
            'status' => CompanyStatus::Lead,
            'source' => RecordSource::Manual,
        ];
    }

    public function aiGenerated(): static
    {
        return $this->state(fn () => ['source' => RecordSource::Ai]);
    }
}
