<?php

namespace App\Filament\Resources\Calls\Schemas;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\RecordSource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CallForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->relationship('company', 'name'),
                Select::make('contact_id')
                    ->relationship('contact', 'id'),
                Select::make('direction')
                    ->options(CallDirection::class)
                    ->required(),
                Select::make('status')
                    ->options(CallStatus::class)
                    ->required(),
                TextInput::make('from_number'),
                TextInput::make('to_number'),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('ended_at'),
                TextInput::make('duration_seconds')
                    ->numeric(),
                TextInput::make('handled_by')
                    ->numeric(),
                Textarea::make('summary')
                    ->columnSpanFull(),
                TextInput::make('external_provider'),
                TextInput::make('external_id'),
                TextInput::make('recording_disk'),
                TextInput::make('recording_path'),
                TextInput::make('recording_bytes')
                    ->numeric(),
                Textarea::make('transcript')
                    ->columnSpanFull(),
                TextInput::make('transcript_status'),
                Toggle::make('consent_obtained'),
                TextInput::make('consent_method'),
                DateTimePicker::make('disclosed_at'),
                DateTimePicker::make('retention_expires_at'),
                DateTimePicker::make('content_erased_at'),
                TextInput::make('erased_by')
                    ->numeric(),
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
