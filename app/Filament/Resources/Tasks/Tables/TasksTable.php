<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (Task $record): ?string => $record->company?->name),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('assignee.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned'),
                TextColumn::make('due_at')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->color(fn (Task $record): ?string => $record->due_at && $record->due_at->isPast() && ! $record->status->isClosed() ? 'danger' : null),
                IconColumn::make('source')
                    ->label('AI')
                    ->icon(fn (Task $record): ?string => $record->isAiGenerated() ? 'heroicon-o-sparkles' : null)
                    ->color('warning')
                    ->tooltip(fn (Task $record): ?string => $record->isAiGenerated() ? 'AI-generated' : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $s) => [$s->value => $s->getLabel()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('start')
                        ->icon('heroicon-o-play')
                        ->visible(fn (Task $r): bool => $r->status === TaskStatus::Open)
                        ->action(fn (Task $r) => $r->start()),
                    Action::make('unblock')
                        ->icon('heroicon-o-play')
                        ->visible(fn (Task $r): bool => $r->status === TaskStatus::Blocked)
                        ->action(fn (Task $r) => $r->unblock()),
                    Action::make('block')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->visible(fn (Task $r): bool => $r->status === TaskStatus::InProgress)
                        ->action(fn (Task $r) => $r->block()),
                    Action::make('complete')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (Task $r): bool => ! $r->status->isClosed())
                        ->action(fn (Task $r) => $r->complete()),
                    Action::make('cancel')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (Task $r): bool => ! $r->status->isClosed())
                        ->action(fn (Task $r) => $r->cancel()),
                    Action::make('reopen')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (Task $r): bool => $r->status->isClosed())
                        ->action(fn (Task $r) => $r->reopen()),
                ])
                    ->label('Status')
                    ->button(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
