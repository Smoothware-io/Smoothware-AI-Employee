<?php

namespace App\Filament\Resources\AiActions\Tables;

use App\Enums\AiActionStatus;
use App\Models\AiAction;
use App\Services\Receptionist\ReceptionistActionApplier;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AiActionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s') // near-real-time queue (no custom Livewire needed)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Proposed')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                TextColumn::make('action_type')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('grounded')
                    ->label('Grounding')
                    ->badge()
                    ->state(fn (AiAction $record): string => ($record->proposed_payload['grounded'] ?? true) ? 'Grounded' : 'Needs human')
                    ->color(fn (string $state): string => $state === 'Grounded' ? 'success' : 'warning'),
                TextColumn::make('confidence_score')
                    ->label('Confidence')
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? round(((float) $state) * 100).'%' : '—')
                    ->alignCenter(),
                TextColumn::make('source_context_version')
                    ->label('Context')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model_id')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(AiActionStatus::cases())->mapWithKeys(fn (AiActionStatus $s) => [$s->value => $s->getLabel()]))
                    ->default(AiActionStatus::Draft->value),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (AiAction $record): bool => $record->isDraft() && $record->action_type === 'receptionist_intake')
                    ->requiresConfirmation()
                    ->modalHeading('Approve & apply')
                    ->modalDescription('Creates the proposed records and links the call. This is the human sign-off before anything is committed.')
                    ->action(function (AiAction $record): void {
                        app(ReceptionistActionApplier::class)->approve($record, Auth::user());
                        Notification::make()->title('Approved — records created')->success()->send();
                    }),
                Action::make('reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (AiAction $record): bool => $record->isDraft())
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why are you rejecting this?')
                            ->required(),
                    ])
                    ->action(function (array $data, AiAction $record): void {
                        app(ReceptionistActionApplier::class)->reject($record, Auth::user(), $data['reason'] ?? 'Rejected by reviewer');
                        Notification::make()->title('Rejected')->send();
                    }),
            ]);
    }
}
