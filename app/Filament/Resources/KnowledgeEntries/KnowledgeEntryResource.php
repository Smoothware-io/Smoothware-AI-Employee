<?php

namespace App\Filament\Resources\KnowledgeEntries;

use App\Filament\Resources\KnowledgeEntries\Pages\CreateKnowledgeEntry;
use App\Filament\Resources\KnowledgeEntries\Pages\EditKnowledgeEntry;
use App\Filament\Resources\KnowledgeEntries\Pages\ListKnowledgeEntries;
use App\Filament\Resources\KnowledgeEntries\Pages\ViewKnowledgeEntry;
use App\Filament\Resources\KnowledgeEntries\Schemas\KnowledgeEntryForm;
use App\Filament\Resources\KnowledgeEntries\Schemas\KnowledgeEntryInfolist;
use App\Filament\Resources\KnowledgeEntries\Tables\KnowledgeEntriesTable;
use App\Models\KnowledgeEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class KnowledgeEntryResource extends Resource
{
    protected static ?string $model = KnowledgeEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Knowledge Base';

    protected static ?string $navigationLabel = 'Knowledge base';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return KnowledgeEntryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return KnowledgeEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeEntries::route('/'),
            'create' => CreateKnowledgeEntry::route('/create'),
            'view' => ViewKnowledgeEntry::route('/{record}'),
            'edit' => EditKnowledgeEntry::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
