<?php

namespace App\Filament\Resources\AiActions;

use App\Filament\Resources\AiActions\Pages\ListAiActions;
use App\Filament\Resources\AiActions\Pages\ViewAiAction;
use App\Filament\Resources\AiActions\Schemas\AiActionInfolist;
use App\Filament\Resources\AiActions\Tables\AiActionsTable;
use App\Models\AiAction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Phase 3 review queue: AI-proposed actions ("AI proposes → human approves").
 * Read + review only — actions are created by the AI, never by hand. Approve/
 * reject live on the table; the queue polls for near-real-time updates.
 */
class AiActionResource extends Resource
{
    protected static ?string $model = AiAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'AI Receptionist';

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = AiAction::query()->pendingReview()->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return AiActionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiActionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiActions::route('/'),
            'view' => ViewAiAction::route('/{record}'),
        ];
    }
}
