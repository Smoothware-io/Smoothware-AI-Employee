<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only activity feed for a company, built from the append-only event log
 * (Phase 1 Company Timeline). No create/edit/delete — the log is immutable.
 */
class TimelineEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'timelineEvents';

    protected static ?string $title = 'Timeline';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                TextColumn::make('action')
                    ->label('Event')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('actor_type')
                    ->label('By')
                    ->badge(),
                TextColumn::make('actorUser.name')
                    ->label('User')
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
