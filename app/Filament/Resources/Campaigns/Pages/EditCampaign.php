<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Services\Outbound\CampaignRunner;
use App\Services\Outbound\OutboundGate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * The campaign's control panel — the same page as its settings, on purpose.
 *
 * Start and pause are BUTTONS, not a status dropdown, because "running" is not a
 * field you edit: it is a thing you do, and this one rings strangers. A dropdown
 * that quietly begins dialling when somebody saves an unrelated change is a
 * terrible way to find that out.
 */
class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label(fn (Campaign $record): string => $record->status === CampaignStatus::Paused
                    ? 'Resume calling'
                    : 'Start calling')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (Campaign $record): bool => in_array(
                    $record->status,
                    [CampaignStatus::Draft, CampaignStatus::Paused],
                    true,
                ))
                ->requiresConfirmation()
                ->modalHeading('Start calling this list?')
                ->modalDescription(function (Campaign $record): string {
                    $p = app(CampaignRunner::class)->progress($record);

                    return "The AI will ring {$p['remaining']} companies, about {$record->calls_per_hour} per hour"
                        .($record->respect_working_hours ? ', during working hours only.' : ', at any hour.')
                        .' You can pause at any time.';
                })
                ->action(function (Campaign $record): void {
                    // Say WHY it will not dial, rather than starting a campaign
                    // that silently does nothing — the exact failure that had us
                    // reading logs for three rounds. Number-specific blockers are
                    // ignored here: this probe number is not a real target.
                    $blockers = array_filter(
                        app(OutboundGate::class)->blockers('+31000000000'),
                        fn (string $b): bool => ! str_contains($b, 'OUTBOUND_TEST_NUMBERS')
                            && ! str_contains($b, 'do-not-contact'),
                    );

                    if ($blockers !== []) {
                        Notification::make()
                            ->title('Calling is not switched on yet')
                            ->body(implode(' ', $blockers))
                            ->warning()->persistent()->send();

                        return;
                    }

                    $record->forceFill([
                        'status' => CampaignStatus::Running,
                        'started_at' => $record->started_at ?? now(),
                        'completed_at' => null,
                    ])->save();

                    Notification::make()
                        ->title('Campaign started')
                        ->body('The first call goes out within a minute.')
                        ->success()->send();
                }),

            Action::make('pause')
                ->label('Pause')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::Running)
                ->action(function (Campaign $record): void {
                    // Progress is kept — pausing is not starting over.
                    $record->forceFill(['status' => CampaignStatus::Paused])->save();

                    Notification::make()
                        ->title('Campaign paused')
                        ->body('No new calls. A call already in progress finishes normally.')
                        ->warning()->send();
                }),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
