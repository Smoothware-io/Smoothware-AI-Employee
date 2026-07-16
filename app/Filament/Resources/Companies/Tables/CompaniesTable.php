<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Enums\CompanyStatus;
use App\Filament\Actions\CallWithAiAction;
use App\Models\Company;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Company $record): ?string => $record->domain),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('industry')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->placeholder('Unassigned'),
                TextColumn::make('contacts_count')
                    ->counts('contacts')
                    ->label('Contacts')
                    ->alignCenter(),
                TextColumn::make('city')
                    ->toggleable()
                    ->placeholder('—')
                    ->description(fn (Company $record): ?string => $record->country),
                TextColumn::make('language')
                    ->label('Speaks')
                    ->badge()
                    ->placeholder('— will ask')
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CompanyStatus::cases())->mapWithKeys(fn (CompanyStatus $s) => [$s->value => $s->getLabel()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Phase 6. Visible only once outbound is enabled — a button that
                // always refuses teaches people to ignore refusals.
                CallWithAiAction::make()
                    ->visible(fn (): bool => (bool) config('outbound.enabled')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
