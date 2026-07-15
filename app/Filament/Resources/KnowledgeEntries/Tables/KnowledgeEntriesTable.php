<?php

namespace App\Filament\Resources\KnowledgeEntries\Tables;

use App\Enums\KnowledgeType;
use App\Enums\PublishStatus;
use App\Jobs\EmbedKnowledgeEntry;
use App\Models\KnowledgeEntry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class KnowledgeEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->weight('bold')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('chunks_count')
                    ->counts('chunks')
                    ->label('Chunks')
                    ->alignCenter(),
                TextColumn::make('last_verified_at')
                    ->label('Verified')
                    ->badge()
                    ->date('d M Y')
                    ->placeholder('Never')
                    ->color(fn (KnowledgeEntry $record): string => $record->isStale() ? 'danger' : 'success'),
                TextColumn::make('source')
                    ->badge()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(KnowledgeType::cases())->mapWithKeys(fn (KnowledgeType $t) => [$t->value => $t->getLabel()])),
                SelectFilter::make('status')
                    ->options(collect(PublishStatus::cases())->mapWithKeys(fn (PublishStatus $s) => [$s->value => $s->getLabel()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Mark as verified')
                    ->modalDescription('Confirms the content is current as of now.')
                    ->action(fn (KnowledgeEntry $record) => $record->update([
                        'last_verified_at' => now(),
                        'verified_by' => Auth::id(),
                    ])),
                Action::make('reembed')
                    ->label('Re-embed')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (KnowledgeEntry $record): bool => $record->isPublished())
                    ->action(function (KnowledgeEntry $record) {
                        EmbedKnowledgeEntry::dispatch($record->getKey());
                        Notification::make()->title('Re-embedding queued')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
