<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Enums\CompanyStatus;
use App\Enums\LawfulBasis;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

                // Asked BEFORE the import runs, not after: the point is to make the
                // question unavoidable at the moment someone loads other people's
                // personal data. See GO-LIVE-LEGAL.md item #2.
                Section::make('Provenance & lawful basis')
                    ->description('Required. This list contains personal data we did not collect from the people in it, so we must be able to say where it came from and under which GDPR basis we are processing it.')
                    ->columns(2)
                    ->schema([
                        Textarea::make('list_source')
                            ->label('Where did this list come from?')
                            ->helperText('Be specific and auditable: the vendor, event, partner, or export it came from — and when. "Old spreadsheet" is not an answer.')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('lawful_basis')
                            ->label('Lawful basis (GDPR Art. 6)')
                            ->options(LawfulBasis::class)
                            ->required()
                            ->live()
                            ->native(false),
                        Textarea::make('lawful_basis_notes')
                            ->label('Justification / LIA reference')
                            ->helperText('Link or reference the Legitimate Interest Assessment, or state the reasoning.')
                            // Required exactly when the chosen basis carries an
                            // assessment burden — the UI mirrors the enum's rule.
                            ->required(fn (Get $get): bool => LawfulBasis::tryFrom((string) $get('lawful_basis'))?->requiresAssessment() ?? false)
                            ->visible(fn (Get $get): bool => filled($get('lawful_basis'))),
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
