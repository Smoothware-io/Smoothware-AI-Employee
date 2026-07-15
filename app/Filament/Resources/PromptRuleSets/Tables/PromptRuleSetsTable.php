<?php

namespace App\Filament\Resources\PromptRuleSets\Tables;

use App\Models\PromptRuleSet;
use App\Services\PromptRuleSetService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PromptRuleSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->formatStateUsing(fn (int $state): string => "v{$state}")
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('rules_count')
                    ->counts('rules')
                    ->label('Rules')
                    ->alignCenter(),
                TextColumn::make('activated_at')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('—'),
                TextColumn::make('activatedBy.name')
                    ->label('Activated by')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('activate')
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->visible(fn (PromptRuleSet $record): bool => ! $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Activate this ruleset')
                    ->modalDescription('This version becomes the ruleset governing all AI conversations. The currently active set is archived.')
                    ->action(function (PromptRuleSet $record) {
                        app(PromptRuleSetService::class)->activate($record, Auth::user());
                        Notification::make()->title("Ruleset v{$record->version} is now active")->success()->send();
                    }),
                EditAction::make(),
            ]);
    }
}
