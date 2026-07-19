<?php

namespace App\Filament\Resources\Calls\Tables;

use App\Enums\CallStatus;
use App\Models\Call;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('direction')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state ? gmdate('i:s', $state) : '—'),
                TextColumn::make('handler.name')
                    ->label('Handled by')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('recording')
                    ->label('Rec.')
                    ->alignCenter()
                    ->icon(fn (Call $record): ?string => $record->hasRecording() ? 'heroicon-m-microphone' : null)
                    ->tooltip(fn (Call $record): ?string => $record->hasRecording() ? 'Has recording' : null),
                IconColumn::make('transcript')
                    ->label('Transcript')
                    ->alignCenter()
                    // Whether the conversation was captured is the first thing a
                    // reviewer scans for, and until now the only way to find out
                    // was to open the edit form.
                    ->icon(fn (Call $record): ?string => filled($record->transcript) ? 'heroicon-m-chat-bubble-left-right' : null)
                    ->color('info')
                    ->tooltip(fn (Call $record): ?string => filled($record->transcript) ? 'Conversation captured' : null),
                IconColumn::make('content_erased_at')
                    ->label('Erased')
                    ->alignCenter()
                    ->icon(fn (Call $record): ?string => $record->isContentErased() ? 'heroicon-m-shield-check' : null)
                    ->color('danger')
                    ->tooltip(fn (Call $record): ?string => $record->isContentErased() ? 'Personal content erased (GDPR)' : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CallStatus::cases())->mapWithKeys(fn (CallStatus $s) => [$s->value => $s->getLabel()])),
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
