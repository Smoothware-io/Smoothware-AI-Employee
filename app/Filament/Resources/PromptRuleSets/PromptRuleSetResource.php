<?php

namespace App\Filament\Resources\PromptRuleSets;

use App\Enums\NavGroup;
use App\Filament\Resources\PromptRuleSets\Pages\CreatePromptRuleSet;
use App\Filament\Resources\PromptRuleSets\Pages\EditPromptRuleSet;
use App\Filament\Resources\PromptRuleSets\Pages\ListPromptRuleSets;
use App\Filament\Resources\PromptRuleSets\Pages\ViewPromptRuleSet;
use App\Filament\Resources\PromptRuleSets\RelationManagers\RulesRelationManager;
use App\Filament\Resources\PromptRuleSets\Schemas\PromptRuleSetForm;
use App\Filament\Resources\PromptRuleSets\Schemas\PromptRuleSetInfolist;
use App\Filament\Resources\PromptRuleSets\Tables\PromptRuleSetsTable;
use App\Models\PromptRuleSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PromptRuleSetResource extends Resource
{
    protected static ?string $model = PromptRuleSet::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::TeachTheAi;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Rules for the AI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'version';

    public static function form(Schema $schema): Schema
    {
        return PromptRuleSetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PromptRuleSetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptRuleSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromptRuleSets::route('/'),
            'create' => CreatePromptRuleSet::route('/create'),
            'view' => ViewPromptRuleSet::route('/{record}'),
            'edit' => EditPromptRuleSet::route('/{record}/edit'),
        ];
    }
}
