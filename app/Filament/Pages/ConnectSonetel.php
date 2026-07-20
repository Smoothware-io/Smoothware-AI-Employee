<?php

namespace App\Filament\Pages;

use App\Enums\NavGroup;
use App\Models\SonetelAccount;
use App\Services\Outbound\SonetelTokenService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * "Connect Sonetel" — a rep links their own telephony account (Phase 6).
 *
 * Per user, not per install: the number a prospect sees ringing should belong to
 * whoever is accountable for the call.
 *
 * The password is typed here, sent straight to Sonetel, exchanged for tokens, and
 * never stored — not in the database, not in the session, not in the event log.
 * What is kept is a refresh token, which is scoped to this one API and revocable
 * at Sonetel without touching the rep's other accounts.
 */
class ConnectSonetel extends Page
{
    protected string $view = 'filament.pages.connect-sonetel';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?string $navigationLabel = 'My phone account';

    protected static ?string $title = 'Sonetel connection';

    protected static ?int $navigationSort = 2;

    public function getAccount(): ?SonetelAccount
    {
        return SonetelAccount::firstWhere('user_id', Auth::id());
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(fn (): string => $this->getAccount() ? 'Reconnect' : 'Connect Sonetel')
                ->icon('heroicon-o-link')
                ->color(fn (): string => $this->getAccount()?->hasFreshToken() ? 'gray' : 'primary')
                ->modalHeading('Connect your Sonetel account')
                ->modalDescription('Your password is sent to Sonetel to obtain a token, and is not stored anywhere by this app.')
                ->modalSubmitActionLabel('Connect')
                ->schema([
                    TextInput::make('username')
                        ->label('Sonetel email')
                        ->email()
                        ->required()
                        // Prefilled on reconnect: the rep already told us who they are.
                        ->default(fn (): ?string => $this->getAccount()?->username),
                    TextInput::make('password')
                        ->label('Sonetel password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->helperText('Used once to get a token. Never saved.'),
                    TextInput::make('sonetel_number')
                        ->label('Your Sonetel number (optional)')
                        ->tel()
                        ->default(fn (): ?string => $this->getAccount()?->sonetel_number)
                        ->helperText('Shown as caller ID. Leave blank and Sonetel picks the best number on your account.'),
                ])
                ->action(function (array $data): void {
                    try {
                        $account = app(SonetelTokenService::class)->connect(
                            user: Auth::user(),
                            username: $data['username'],
                            password: $data['password'],
                        );

                        if (filled($data['sonetel_number'] ?? null)) {
                            $account->update(['sonetel_number' => $data['sonetel_number']]);
                        }

                        Notification::make()
                            ->title('Sonetel connected')
                            ->body('Token valid until '.$account->expires_at?->diffForHumans().'. It refreshes itself.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        // Sonetel's own message — "Bad credentials" is actionable,
                        // "something went wrong" is not.
                        Notification::make()
                            ->title('Could not connect')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            Action::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->getAccount() !== null)
                ->requiresConfirmation()
                ->modalHeading('Disconnect Sonetel?')
                ->modalDescription('Your tokens are deleted and this app can no longer place calls as you. Reconnecting takes a password.')
                ->action(function (): void {
                    $this->getAccount()?->delete();

                    Notification::make()->title('Sonetel disconnected')->warning()->send();
                }),
        ];
    }
}
