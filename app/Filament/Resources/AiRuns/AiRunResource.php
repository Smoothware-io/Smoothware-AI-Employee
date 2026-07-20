<?php

namespace App\Filament\Resources\AiRuns;

use App\Enums\NavGroup;
use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Filament\Resources\AiRuns\Schemas\AiRunInfolist;
use App\Filament\Resources\AiRuns\Tables\AiRunsTable;
use App\Models\AiRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of AI invocations and their ops metrics (grounding, fallback
 * rate, latency, tokens) — the raw data for the Phase 8 AI-ops dashboard.
 */
class AiRunResource extends Resource
{
    protected static ?string $model = AiRun::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::AiActivity;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'AI activity log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    public static function infolist(Schema $schema): Schema
    {
        return AiRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRuns::route('/'),
            'view' => ViewAiRun::route('/{record}'),
        ];
    }
}
