<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Enums\AnalysisPriority;
use App\Enums\CompanyStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->columnSpanFull(),
                        TextInput::make('domain'),
                        Select::make('status')->options(CompanyStatus::class)->default('lead')->required(),
                        TextInput::make('email')->label('Email address')->email(),
                        TextInput::make('phone')->tel(),
                        TextInput::make('industry'),
                        Select::make('owner_id')->relationship('owner', 'name')->label('Owner')->searchable(),
                        TextInput::make('city'),
                        TextInput::make('country')->required()->default('NL'),
                        TextInput::make('address')->columnSpanFull(),
                        TextInput::make('postal_code'),
                    ]),

                // Human-owned analysis — the rep's judgment. The AI NEVER writes
                // here; its analysis lives in a separate, read-only panel.
                Section::make('Manual analysis')
                    ->description('Your assessment. The AI never edits this — its analysis is shown separately, with disagreements flagged.')
                    ->relationship('manualAnalysis')
                    ->columns(2)
                    ->schema([
                        Select::make('priority')
                            ->options(AnalysisPriority::class)
                            ->native(false),
                        Textarea::make('pain_points')->columnSpanFull()->rows(2),
                        Textarea::make('opportunities')->columnSpanFull()->rows(2),
                        Textarea::make('notes')->columnSpanFull()->rows(3),
                    ]),
            ]);
    }
}
