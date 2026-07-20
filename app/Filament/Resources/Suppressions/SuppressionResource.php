<?php

namespace App\Filament\Resources\Suppressions;

use App\Enums\NavGroup;
use App\Filament\Resources\Suppressions\Pages\CreateSuppression;
use App\Filament\Resources\Suppressions\Pages\ListSuppressions;
use App\Filament\Resources\Suppressions\Schemas\SuppressionForm;
use App\Filament\Resources\Suppressions\Tables\SuppressionsTable;
use App\Models\Suppression;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * "Do not contact" — the list that makes an objection enforceable.
 *
 * No edit page and no delete: a suppression is a fact someone stated, not a
 * record we curate. Mistakes are RELEASED (with a reason, from the table), which
 * leaves the trail intact.
 */
class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Leads;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Do not contact';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $recordTitleAttribute = 'value_raw';

    public static function form(Schema $schema): Schema
    {
        return SuppressionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppressionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppressions::route('/'),
            'create' => CreateSuppression::route('/create'),
        ];
    }
}
