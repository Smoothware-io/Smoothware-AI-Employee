<?php

namespace Database\Factories;

use App\Enums\NoteCategory;
use App\Enums\RecordSource;
use App\Models\Company;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'category' => NoteCategory::Internal,
            'body' => fake()->paragraph(),
            'source' => RecordSource::Manual,
        ];
    }
}
