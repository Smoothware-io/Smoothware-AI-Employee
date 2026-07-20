<?php

namespace App\Filament\Pages;

use App\Enums\NavGroup;
use App\Models\GoogleCalendarAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * "Connect Google Calendar" — a rep links their own calendar so the AI never
 * books over a real meeting.
 *
 * Per user, like the Sonetel page. The calendar being protected is personal, and
 * a shared service account would make one person's private appointments
 * readable by everyone in the CRM.
 */
class ConnectGoogleCalendar extends Page
{
    protected string $view = 'filament.pages.connect-google-calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?string $navigationLabel = 'My calendar';

    protected static ?string $title = 'Google Calendar';

    protected static ?int $navigationSort = 5;

    public function getAccount(): ?GoogleCalendarAccount
    {
        return GoogleCalendarAccount::firstWhere('user_id', Auth::id());
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'));
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(fn (): string => $this->getAccount() ? 'Reconnect' : 'Connect Google Calendar')
                ->icon('heroicon-o-link')
                ->color(fn (): string => $this->getAccount() ? 'gray' : 'primary')
                // Nothing to click when the server has no credentials — a button
                // that always errors is worse than no button.
                ->visible(fn (): bool => $this->isConfigured())
                ->url(fn (): string => route('google.calendar.redirect')),

            Action::make('toggleBusy')
                ->label(fn (): string => $this->getAccount()?->block_from_busy
                    ? 'Stop blocking from my calendar'
                    : 'Block from my calendar')
                ->icon('heroicon-o-shield-check')
                ->visible(fn (): bool => $this->getAccount() !== null)
                ->action(function (): void {
                    $account = $this->getAccount();
                    $account->update(['block_from_busy' => ! $account->block_from_busy]);

                    Notification::make()
                        ->title($account->block_from_busy
                            ? 'The AI will avoid times you are busy'
                            : 'The AI will ignore your calendar')
                        ->success()
                        ->send();
                }),

            Action::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->getAccount() !== null)
                ->requiresConfirmation()
                ->modalHeading('Disconnect Google Calendar?')
                ->modalDescription('The AI will stop checking your calendar and stop adding meetings to it. Existing meetings stay where they are.')
                ->action(function (): void {
                    $this->getAccount()?->delete();

                    Notification::make()->title('Google Calendar disconnected')->warning()->send();
                }),
        ];
    }
}
