<?php

namespace App\Filament\Resources\AiRuns\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AiRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('uuid')
                    ->label('UUID'),
                TextEntry::make('kind'),
                TextEntry::make('model_id')
                    ->placeholder('-'),
                TextEntry::make('context_version')
                    ->placeholder('-'),
                TextEntry::make('subject_type')
                    ->placeholder('-'),
                TextEntry::make('subject_id')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('grounded')
                    ->boolean(),
                IconEntry::make('fallback_to_human')
                    ->boolean(),
                TextEntry::make('latency_ms')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('input_tokens')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('output_tokens')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('cost')
                    ->money()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
