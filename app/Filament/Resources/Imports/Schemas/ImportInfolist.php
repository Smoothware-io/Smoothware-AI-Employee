<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Models\Import;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('original_name')->label('File'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('created_at')->dateTime('d M Y H:i'),
                        TextEntry::make('creator.name')->label('Uploaded by')->placeholder('—'),
                        TextEntry::make('campaign.name')->label('Campaign')->placeholder('—'),
                        TextEntry::make('defaultOwner.name')->label('Default owner')->placeholder('—'),
                    ]),

                Section::make('Provenance & lawful basis')
                    ->description('Where this list came from and why we may process it — see GO-LIVE-LEGAL.md item #2.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('list_source')
                            ->label('List source')
                            ->placeholder('— not recorded')
                            ->columnSpanFull(),
                        TextEntry::make('lawful_basis')
                            ->label('Lawful basis')
                            ->badge()
                            ->placeholder('— not recorded'),
                        TextEntry::make('lawful_basis_notes')
                            ->label('Justification / LIA reference')
                            // Flags the one combination that looks answered but isn't:
                            // a basis that needs an assessment, with no reasoning recorded.
                            ->placeholder(fn (Import $record): string => $record->hasUnjustifiedBasis()
                                ? '⚠ This basis requires a recorded assessment — none given'
                                : '—')
                            ->color(fn (Import $record): ?string => $record->hasUnjustifiedBasis() ? 'danger' : null),
                    ]),

                Section::make('Result')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('create_count')->label('Create')->numeric(),
                        TextEntry::make('match_count')->label('Match (deduped)')->numeric(),
                        TextEntry::make('skip_count')->label('Skip')->numeric(),
                        TextEntry::make('invalid_count')->label('Invalid')->numeric(),
                        TextEntry::make('error')
                            ->color('danger')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
