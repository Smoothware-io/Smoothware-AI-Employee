<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Enums\PreferredChannel;
use App\Enums\RecordSource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->required(),
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name'),
                TextInput::make('job_title'),
                Toggle::make('is_decision_maker')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                Select::make('preferred_channel')
                    ->label('Prefers to be contacted by')
                    ->options(PreferredChannel::class)
                    ->placeholder('Not stated')
                    ->helperText('Leave blank unless they actually told us — a guess here would look like a stated preference.')
                    ->native(false),
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
