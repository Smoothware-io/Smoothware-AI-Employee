<?php

namespace App\Filament\Resources\Calls\Schemas;

use App\Models\Call;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read view of a call — what a rep opens when they want to know what was
 * actually said.
 *
 * This exists because the scaffolded resource offered only an EDIT form, where
 * the transcript was a raw <textarea> of `CALLER: ...` / `AI: ...` lines. That is
 * a data dump, not a record of a conversation, and it put the one artefact both
 * we and the client need to read behind a form built for changing data rather
 * than reading it.
 */
class CallInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Call')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('company.name')->label('Company')->placeholder('—'),
                        TextEntry::make('direction')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('started_at')->label('Started')->dateTime('d M Y, H:i')->placeholder('—'),
                        TextEntry::make('duration_seconds')
                            ->label('Duration')
                            ->formatStateUsing(fn (?int $state): string => $state ? gmdate('i:s', $state) : '—'),
                        TextEntry::make('transcript_status')->label('Transcript')->placeholder('—'),
                    ]),

                Section::make('Conversation')
                    ->description('Both sides of the call, as transcribed by the model.')
                    ->schema([
                        ViewEntry::make('transcript')
                            ->hiddenLabel()
                            ->view('filament.calls.transcript'),
                    ]),

                Section::make('Summary')
                    ->collapsed()
                    // Only worth opening when something produced one.
                    ->visible(fn (Call $record): bool => filled($record->summary))
                    ->schema([
                        TextEntry::make('summary')->hiddenLabel()->prose(),
                    ]),
            ]);
    }
}
