<?php

namespace App\Filament\Resources\Imports\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('original_name'),
                TextEntry::make('disk'),
                TextEntry::make('path'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('defaultOwner.name')
                    ->label('Default owner')
                    ->placeholder('-'),
                TextEntry::make('default_status')
                    ->placeholder('-'),
                TextEntry::make('default_industry')
                    ->placeholder('-'),
                TextEntry::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('-'),
                TextEntry::make('create_count')
                    ->numeric(),
                TextEntry::make('match_count')
                    ->numeric(),
                TextEntry::make('skip_count')
                    ->numeric(),
                TextEntry::make('invalid_count')
                    ->numeric(),
                TextEntry::make('error')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_by')
                    ->numeric()
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
