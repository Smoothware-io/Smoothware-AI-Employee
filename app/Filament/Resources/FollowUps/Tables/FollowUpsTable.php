<?php

namespace App\Filament\Resources\FollowUps\Tables;

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpTrigger;
use App\Models\FollowUp;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The follow-up ledger — read-only. It records what the automation decided and
 * why, including the decisions that suppressed work. Editing history would
 * defeat the point.
 */
class FollowUpsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Fired')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable()->wrap(),
                TextColumn::make('trigger')->badge()->color('gray'),
                TextColumn::make('status')->badge(),
                TextColumn::make('rule.name')
                    ->label('Rule')
                    // The rule can be archived after firing; the snapshot still holds
                    // the name it had at the time.
                    ->state(fn (FollowUp $record): string => $record->rule?->name
                        ?? ($record->rule_snapshot['name'] ?? '— AI-suggested'))
                    ->wrap(),
                TextColumn::make('task.title')
                    ->label('Task created')
                    ->placeholder('— none')
                    ->url(fn (FollowUp $record): ?string => $record->task_id
                        ? "/admin/tasks/{$record->task_id}/edit"
                        : null)
                    ->wrap(),
                TextColumn::make('reason')->limit(60)->toggleable()->wrap(),
                TextColumn::make('source')->badge()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(FollowUpStatus::class),
                SelectFilter::make('trigger')->options(FollowUpTrigger::class),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
