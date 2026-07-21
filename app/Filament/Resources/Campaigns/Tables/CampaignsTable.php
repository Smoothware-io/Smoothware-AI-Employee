<?php

namespace App\Filament\Resources\Campaigns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),
                // First thing a human wants to know when they open this list:
                // is anything ringing people right now?
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('companies_count')->counts('companies')->label('Companies')->alignCenter(),
                TextColumn::make('description')->limit(60)->placeholder('—')->toggleable(),
                TextColumn::make('calls_per_hour')
                    ->label('Pace')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state}/hour" : '—')
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime('d M Y')->sortable(),
            ])
            ->recordActions([
                EditAction::make()->label('Open'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
