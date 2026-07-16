<?php

namespace App\Filament\Resources\FollowUpRules;

use App\Filament\Resources\FollowUpRules\Pages\CreateFollowUpRule;
use App\Filament\Resources\FollowUpRules\Pages\EditFollowUpRule;
use App\Filament\Resources\FollowUpRules\Pages\ListFollowUpRules;
use App\Filament\Resources\FollowUpRules\Schemas\FollowUpRuleForm;
use App\Filament\Resources\FollowUpRules\Tables\FollowUpRulesTable;
use App\Models\FollowUpRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FollowUpRuleResource extends Resource
{
    protected static ?string $model = FollowUpRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Rules';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return FollowUpRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FollowUpRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFollowUpRules::route('/'),
            'create' => CreateFollowUpRule::route('/create'),
            'edit' => EditFollowUpRule::route('/{record}/edit'),
        ];
    }
}
