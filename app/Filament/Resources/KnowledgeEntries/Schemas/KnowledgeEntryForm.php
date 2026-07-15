<?php

namespace App\Filament\Resources\KnowledgeEntries\Schemas;

use App\Enums\KnowledgeType;
use App\Enums\PublishStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class KnowledgeEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(KnowledgeType::class)
                    ->required(),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Select::make('status')
                    ->options(PublishStatus::class)
                    ->default(PublishStatus::Draft->value)
                    ->helperText('Only published entries are used to answer AI queries.')
                    ->required(),
                RichEditor::make('body')
                    ->helperText('The text used for retrieval. Re-embedded automatically on save.')
                    ->columnSpanFull(),
                KeyValue::make('data')
                    ->label('Structured data')
                    ->helperText('Type-specific fields, e.g. pricing factors/ranges, portfolio client/URL.')
                    ->columnSpanFull(),
                DateTimePicker::make('last_verified_at')
                    ->label('Last verified')
                    ->seconds(false),
            ]);
    }
}
