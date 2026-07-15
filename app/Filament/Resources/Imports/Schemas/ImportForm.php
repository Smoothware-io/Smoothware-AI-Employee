<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Enums\CompanyStatus;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('CSV file')
                    ->description('Headers are auto-mapped (name, domain, email, phone, industry, first/last name). After upload you get a preview — nothing is created until you commit.')
                    ->schema([
                        FileUpload::make('path')
                            ->label('CSV file')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->storeFileNamesIn('original_name')
                            ->required(),
                    ]),

                Section::make('Defaults for imported companies')
                    ->columns(2)
                    ->schema([
                        Select::make('default_owner_id')
                            ->label('Owner')
                            ->relationship('defaultOwner', 'name')
                            ->searchable(),
                        Select::make('default_status')
                            ->label('Status')
                            ->options(CompanyStatus::class)
                            ->default('lead'),
                        Select::make('campaign_id')
                            ->label('Campaign')
                            ->relationship('campaign', 'name')
                            ->searchable()
                            ->createOptionForm([
                                TextInput::make('name')->required(),
                            ]),
                        TextInput::make('default_industry')
                            ->label('Industry (fallback when the CSV has none)'),
                    ]),
            ]);
    }
}
