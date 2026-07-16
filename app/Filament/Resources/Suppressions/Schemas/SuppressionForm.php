<?php

namespace App\Filament\Resources\Suppressions\Schemas;

use App\Enums\SuppressionSource;
use App\Enums\SuppressionType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SuppressionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Never contact again')
                    ->description('Someone told us to stop. This is permanent and takes effect immediately — imports will refuse to create them, and any future outbound will refuse to dial. The right to object is absolute; it is not a preference we weigh up.')
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->label('What should we stop using?')
                            ->options(SuppressionType::class)
                            ->required()
                            ->live()
                            ->native(false),

                        Select::make('source')
                            ->label('How did we find out?')
                            ->options(SuppressionSource::class)
                            ->default(SuppressionSource::Manual->value)
                            ->required()
                            ->native(false),

                        // Typed however the rep saw it; normalised on save, so
                        // "+31 (0)6 …" and "06…" both match later.
                        TextInput::make('value_raw')
                            ->label('Number, address or domain')
                            ->helperText('Type it however you have it — we normalise it. A domain stops every contact at that company.')
                            ->required()
                            ->columnSpanFull(),

                        Textarea::make('reason')
                            ->label('What did they say?')
                            ->helperText('Worth recording. A regulator asking "why did you stop?" is far easier to answer than "why did you not?"')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
