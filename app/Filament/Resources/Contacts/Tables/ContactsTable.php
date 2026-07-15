<?php

namespace App\Filament\Resources\Contacts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable(['first_name', 'last_name'])
                    ->description(fn ($record): ?string => $record->job_title),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_decision_maker')
                    ->label('Decision-maker')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('email')
                    ->icon('heroicon-m-envelope')
                    ->copyable()
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('phone')
                    ->icon('heroicon-m-phone')
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->badge()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_decision_maker')
                    ->label('Decision-makers'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
