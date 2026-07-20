<?php

namespace App\Filament\Resources\Imports;

use App\Enums\NavGroup;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Imports\RelationManagers\RowsRelationManager;
use App\Filament\Resources\Imports\Schemas\ImportForm;
use App\Filament\Resources\Imports\Schemas\ImportInfolist;
use App\Filament\Resources\Imports\Tables\ImportsTable;
use App\Models\Import;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ImportResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Leads;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Import leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $recordTitleAttribute = 'original_name';

    public static function form(Schema $schema): Schema
    {
        return ImportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImports::route('/'),
            'create' => CreateImport::route('/create'),
            'view' => ViewImport::route('/{record}'),
        ];
    }
}
