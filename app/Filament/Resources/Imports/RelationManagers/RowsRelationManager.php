<?php

namespace App\Filament\Resources\Imports\RelationManagers;

use App\Enums\ImportRowDisposition;
use App\Models\ImportRow;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only preview of the staged rows and their disposition — what the commit
 * will create vs. match vs. skip.
 */
class RowsRelationManager extends RelationManager
{
    protected static string $relationship = 'rows';

    protected static ?string $title = 'Preview';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('row_number')
            ->columns([
                TextColumn::make('row_number')->label('#')->alignCenter(),
                TextColumn::make('disposition')->badge(),
                TextColumn::make('name')
                    ->label('Company')
                    ->state(fn (ImportRow $record): string => $record->mapped['name'] ?? '—'),
                TextColumn::make('company.name')
                    ->label('Matched / created')
                    ->placeholder('—'),
                TextColumn::make('errors')
                    ->formatStateUsing(fn (?array $state): string => $state ? implode('; ', $state) : '')
                    ->color('danger')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('disposition')
                    ->options(collect(ImportRowDisposition::cases())->mapWithKeys(fn ($d) => [$d->value => $d->getLabel()])),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
