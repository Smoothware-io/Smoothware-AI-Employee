<?php

namespace App\Filament\Resources\Notes\Schemas;

use App\Enums\NoteCategory;
use App\Enums\RecordSource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class NoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required(),
                Select::make('category')
                    ->options(NoteCategory::class)
                    ->default('internal')
                    ->required(),
                Textarea::make('body')
                    ->required()
                    ->columnSpanFull(),
                Select::make('source')
                    ->options(RecordSource::class)
                    ->default('manual')
                    ->required(),
                Select::make('ai_action_id')
                    ->relationship('aiAction', 'id'),
                TextInput::make('created_by')
                    ->numeric(),
                DateTimePicker::make('archived_at'),
            ]);
    }
}
