<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Models\Campaign;
use App\Services\Outbound\CampaignRunner;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * One page for the whole campaign: what it is, how it calls, how far it got.
 *
 * Deliberately NOT split into a settings page, a control page and a progress
 * page. Three pages for one job is how an admin panel becomes something a
 * salesperson is afraid of — and everything here answers a single question,
 * "what is this campaign doing", so it lives in a single place.
 *
 * Every number is editable. A client's pace and patience are theirs, and
 * re-tuning them must never mean asking us for a deploy.
 */
class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The list')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),

                        Textarea::make('description')
                            ->label('Notes for your team')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('objective')
                            ->label('What should the AI achieve on these calls?')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('For example: introduce Smoothware, find out whether they are unhappy with their current website, and book a free intro meeting.')
                            ->helperText('Told to the AI on every call in this campaign. Leave empty to use the standard persona only.'),
                    ]),

                Section::make('Progress')
                    ->description('Live, counted from the calls actually placed.')
                    // Nothing to show until the campaign exists.
                    ->visible(fn (?Campaign $record): bool => $record !== null)
                    ->schema([
                        Text::make(function (?Campaign $record): string {
                            if ($record === null) {
                                return '';
                            }

                            $p = app(CampaignRunner::class)->progress($record);

                            return "{$p['reached']} reached · {$p['attempted']} of {$p['callable']} tried · "
                                ."{$p['remaining']} to go"
                                .($p['no_phone'] > 0 ? " · {$p['no_phone']} have no phone number" : '');
                        }),
                    ]),

                Section::make('How it calls')
                    ->description('Pace and manners. Change them any time — a running campaign picks them up on its next call.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('calls_per_hour')
                            ->label('Calls per hour')
                            ->numeric()->required()->default(6)
                            ->minValue(1)->maxValue(60)
                            ->helperText('6 = one every ten minutes. Higher is faster; too high and it stops sounding like a business.'),

                        TextInput::make('max_call_minutes')
                            ->label('Maximum call length (minutes)')
                            ->numeric()->required()->default(3)
                            ->minValue(1)->maxValue(30)
                            ->helperText('The AI is told to wrap up within this. A cold call that overruns has usually stopped being welcome.'),

                        TextInput::make('max_attempts')
                            ->label('Attempts per company')
                            ->numeric()->required()->default(2)
                            ->minValue(1)->maxValue(5)
                            ->helperText('Including the first. Once somebody actually answers, this campaign never rings them again.'),

                        TextInput::make('retry_after_hours')
                            ->label('Wait before trying again (hours)')
                            ->numeric()->required()->default(24)
                            ->minValue(1)->maxValue(336)
                            ->helperText('24 means a missed call is retried tomorrow, not in ten minutes.'),

                        Toggle::make('respect_working_hours')
                            ->label('Only call during working hours')
                            ->default(true)
                            ->columnSpanFull()
                            ->helperText('Uses the same hours as Settings → Working hours. Switching this off lets it ring people in the evening — rarely what you want, and for telemarketing sometimes unlawful.'),
                    ]),
            ]);
    }
}
