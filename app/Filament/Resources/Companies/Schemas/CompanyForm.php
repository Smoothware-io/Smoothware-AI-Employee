<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Enums\CompanyStatus;
use App\Enums\RecordSource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('domain'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('address'),
                TextInput::make('city'),
                TextInput::make('postal_code'),
                TextInput::make('country')
                    ->required()
                    ->default('NL'),
                TextInput::make('industry'),
                Select::make('status')
                    ->options(CompanyStatus::class)
                    ->default('lead')
                    ->required(),
                Select::make('owner_id')
                    ->relationship('owner', 'name'),
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
