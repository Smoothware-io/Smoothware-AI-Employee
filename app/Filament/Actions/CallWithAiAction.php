<?php

namespace App\Filament\Actions;

use App\Models\Company;
use App\Services\Outbound\OutboundGate;
use App\Services\Outbound\SonetelDialer;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Call this company with the AI" — the trigger, from the record (Phase 6).
 *
 * The number is taken from the COMPANY, not from config: this is a CRM action on
 * a real prospect, not a test harness. It can be overridden in the modal (a rep
 * often has a better number than the sheet did), and the objective is per-call.
 *
 * The gate is checked BEFORE the modal opens and again inside the dialler. The
 * first check is so a rep sees why they cannot call rather than clicking into a
 * dead end; the second is because a UI check is a courtesy, not a control.
 */
class CallWithAiAction
{
    public static function make(): Action
    {
        return Action::make('callWithAi')
            ->label('Call with AI')
            ->icon('heroicon-o-phone-arrow-up-right')
            ->color('danger')
            ->modalHeading('Let the AI call this company')
            ->modalSubmitActionLabel('Place the call')
            ->schema(fn (Company $record): array => [
                Placeholder::make('gates')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => self::gateSummary($record)),

                TextInput::make('phone')
                    ->label('Number to call')
                    ->tel()
                    ->default($record->phone ?? $record->contacts()->first()?->phone)
                    ->helperText('From the company record. Change it if you have a better one.')
                    ->required(),

                Placeholder::make('language')
                    ->label('Language the AI will speak')
                    ->content(fn (): string => $record->spokenLanguage()?->getLabel()
                        ?? 'Unknown — it will open in Dutch and ask which they prefer.'),

                Textarea::make('objective')
                    ->label('What should the AI try to achieve?')
                    ->placeholder('e.g. Introduce Smoothware, find out who handles their website, offer the free 30-minute intro call.')
                    ->helperText('Recorded on the call, so the recording can be read against the intent it was placed with.')
                    ->rows(3),
            ])
            ->action(function (Company $record, array $data): void {
                try {
                    $call = app(SonetelDialer::class)->call(
                        phone: $data['phone'],
                        company: $record,
                        objective: $data['objective'] ?? null,
                    );

                    $call->forceFill(['objective' => $data['objective'] ?? null])->save();

                    Notification::make()
                        ->title('Calling…')
                        ->body('The AI leg connects first, then '.$data['phone'].' rings.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    // The gate's refusal is the message. Never a silent no-op.
                    Notification::make()
                        ->title('Call refused')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /** Why this call would be refused, shown before the rep commits to it. */
    private static function gateSummary(Company $record): HtmlString
    {
        $blockers = app(OutboundGate::class)->blockers(
            phone: (string) ($record->phone ?? $record->contacts()->first()?->phone ?? ''),
            email: $record->email,
            domain: $record->domain,
        );

        if ($blockers === []) {
            return new HtmlString(
                '<span class="fi-color-success">Ready to call. The AI will identify itself as an AI first — that is not optional.</span>'
            );
        }

        $items = collect($blockers)->map(fn (string $b): string => '<li>'.e($b).'</li>')->implode('');

        return new HtmlString(
            '<strong class="fi-color-danger">This call will be refused:</strong><ul>'.$items.'</ul>'
        );
    }
}
