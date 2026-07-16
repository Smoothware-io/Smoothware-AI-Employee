<?php

namespace App\Filament\Resources\Suppressions\Tables;

use App\Enums\SuppressionSource;
use App\Enums\SuppressionType;
use App\Models\Suppression;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SuppressionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('suppressed_at', 'desc')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('value_raw')
                    ->label('Address')
                    ->searchable(['value_raw', 'value_normalized'])
                    ->description(fn (Suppression $r): string => 'matches as: '.$r->value_normalized)
                    ->weight('bold'),
                TextColumn::make('source')->badge(),
                TextColumn::make('reason')->limit(50)->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('suppressed_at')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('creator.name')->label('Recorded by')->placeholder('—')->toggleable(),
                TextColumn::make('released_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Suppression $r): string => $r->isActive() ? 'Active' : 'Released')
                    ->color(fn (Suppression $r): string => $r->isActive() ? 'danger' : 'gray')
                    ->description(fn (Suppression $r): ?string => $r->released_reason),
            ])
            ->filters([
                SelectFilter::make('type')->options(SuppressionType::class),
                SelectFilter::make('source')->options(SuppressionSource::class),
                TernaryFilter::make('released_at')
                    ->label('Released')
                    ->nullable()
                    ->placeholder('Active only')
                    ->trueLabel('Released only')
                    ->falseLabel('Active only'),
            ])
            ->recordActions([
                // Release, never delete. Letting the system contact someone again
                // is consequential, so it needs a reason and leaves a trail.
                Action::make('release')
                    ->label('Release')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Suppression $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Release this suppression?')
                    ->modalDescription('This lets us contact them again. Only do this if it was recorded in error or they have explicitly re-consented.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why is it safe to contact them again?')
                            ->required(),
                    ])
                    ->action(function (Suppression $record, array $data): void {
                        $record->release($data['reason'], Auth::id());
                        Notification::make()->title('Suppression released')->warning()->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
