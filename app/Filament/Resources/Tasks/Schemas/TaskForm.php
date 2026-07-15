<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\RecordSource;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->relationship('company', 'name'),
                Select::make('type')
                    ->options(TaskType::class)
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(TaskStatus::class)
                    ->default('open')
                    ->required(),
                TextInput::make('status_reason'),
                TextInput::make('assigned_to')
                    ->numeric(),
                DateTimePicker::make('due_at'),
                DateTimePicker::make('completed_at'),
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
