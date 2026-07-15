<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('uuid')
                    ->label('UUID')
                    ->required(),
                TextInput::make('kind')
                    ->required(),
                TextInput::make('model_id'),
                TextInput::make('context_version'),
                TextInput::make('subject_type'),
                TextInput::make('subject_id')
                    ->numeric(),
                Toggle::make('grounded')
                    ->required(),
                Toggle::make('fallback_to_human')
                    ->required(),
                TextInput::make('retrieved_chunk_ids'),
                TextInput::make('latency_ms')
                    ->numeric(),
                TextInput::make('input_tokens')
                    ->numeric(),
                TextInput::make('output_tokens')
                    ->numeric(),
                TextInput::make('cost')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('meta'),
            ]);
    }
}
