<?php

namespace App\Filament\Resources\FollowUps;

use App\Enums\NavGroup;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Resources\FollowUps\Tables\FollowUpsTable;
use App\Models\FollowUp;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of the follow-up ledger: what the automation decided, and why.
 * No create/edit pages — history is recorded, not authored.
 */
class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::AiActivity;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Follow-ups sent';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function table(Table $table): Table
    {
        return FollowUpsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFollowUps::route('/'),
        ];
    }
}
