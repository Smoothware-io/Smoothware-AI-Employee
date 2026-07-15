<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Enums\CompanyStatus;
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
                    ->placeholder('—'),
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
