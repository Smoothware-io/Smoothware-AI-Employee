<?php

namespace App\Filament\Resources\AiActions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AiActionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('action_type'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('target_type')
                    ->placeholder('-'),
                TextEntry::make('target_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('confidence_score')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('source_context_version')
                    ->placeholder('-'),
                TextEntry::make('model_id')
                    ->placeholder('-'),
                TextEntry::make('ai_run_id')
                    ->placeholder('-'),
                TextEntry::make('requested_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('reviewed_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('reviewed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('review_notes')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('applied_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('archived_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
