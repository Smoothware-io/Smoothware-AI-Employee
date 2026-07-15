<?php

namespace App\Filament\Resources\Calls\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->searchable(),
                TextColumn::make('contact.id')
                    ->searchable(),
                TextColumn::make('direction')
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('from_number')
                    ->searchable(),
                TextColumn::make('to_number')
                    ->searchable(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('handled_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('external_provider')
                    ->searchable(),
                TextColumn::make('external_id')
                    ->searchable(),
                TextColumn::make('recording_disk')
                    ->searchable(),
                TextColumn::make('recording_path')
                    ->searchable(),
                TextColumn::make('recording_bytes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('transcript_status')
                    ->searchable(),
                IconColumn::make('consent_obtained')
                    ->boolean(),
                TextColumn::make('consent_method')
                    ->searchable(),
                TextColumn::make('disclosed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('retention_expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('content_erased_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('erased_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('source')
                    ->badge()
                    ->searchable(),
                TextColumn::make('aiAction.id')
                    ->searchable(),
                TextColumn::make('created_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('archived_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
