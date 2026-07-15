<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'original_name' => 'leads.csv',
            'disk' => 'local',
            'path' => 'imports/'.fake()->uuid().'.csv',
            'status' => ImportStatus::Pending,
        ];
    }
}
