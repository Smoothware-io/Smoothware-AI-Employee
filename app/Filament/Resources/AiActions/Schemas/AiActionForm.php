<?php

namespace App\Filament\Resources\AiActions\Schemas;

use App\Enums\AiActionStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AiActionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('action_type')
                    ->required(),
                Select::make('status')
                    ->options(AiActionStatus::class)
                    ->default('draft')
                    ->required(),
                TextInput::make('proposed_payload')
                    ->required(),
                TextInput::make('target_type'),
                TextInput::make('target_id')
                    ->numeric(),
                TextInput::make('confidence_score')
                    ->numeric(),
                TextInput::make('source_context_version'),
                TextInput::make('model_id'),
                TextInput::make('ai_run_id'),
                TextInput::make('requested_by')
                    ->numeric(),
                TextInput::make('reviewed_by')
                    ->numeric(),
                DateTimePicker::make('reviewed_at'),
                Textarea::make('review_notes')
                    ->columnSpanFull(),
                DateTimePicker::make('applied_at'),
                DateTimePicker::make('archived_at'),
            ]);
    }
}
