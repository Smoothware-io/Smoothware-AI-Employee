<?php

namespace App\Filament\Resources\Imports\Tables;

use App\Enums\ImportStatus;
use App\Jobs\CommitImport;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('original_name')
                    ->label('File')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('create_count')->label('Create')->alignCenter()->color('success'),
                TextColumn::make('match_count')->label('Match')->alignCenter()->color('info'),
                TextColumn::make('skip_count')->label('Skip')->alignCenter()->color('gray'),
                TextColumn::make('invalid_count')->label('Invalid')->alignCenter()->color('danger'),
                TextColumn::make('campaign.name')->label('Campaign')->placeholder('—')->toggleable(),
                TextColumn::make('lawful_basis')
                    ->label('Basis')
                    ->badge()
                    ->placeholder('— not recorded')
                    // A basis that needs an assessment but has no reasoning recorded
                    // is worse than a blank one: it looks answered. Say so in the list.
                    ->tooltip(fn (Import $record): ?string => $record->hasUnjustifiedBasis()
                        ? 'Requires a recorded assessment — none given'
                        : null)
                    ->icon(fn (Import $record): ?string => $record->hasUnjustifiedBasis()
                        ? 'heroicon-o-exclamation-triangle'
                        : null)
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('commit')
                    ->label('Commit')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Import $record): bool => $record->status === ImportStatus::Previewed)
                    ->requiresConfirmation()
                    ->modalHeading('Commit import')
                    ->modalDescription(fn (Import $record): string => "Creates {$record->create_count} new companies, links {$record->match_count} existing, and queues analysis. Skips {$record->skip_count} empty and {$record->invalid_count} invalid rows.")
                    ->action(function (Import $record): void {
                        CommitImport::dispatchSync($record->getKey());
                        Notification::make()->title('Import committed')->success()->send();
                    }),
            ]);
    }
}
