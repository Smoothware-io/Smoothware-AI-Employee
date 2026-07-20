<?php

namespace App\Filament\Resources\Calls;

use App\Enums\NavGroup;
use App\Filament\Resources\Calls\Pages\CreateCall;
use App\Filament\Resources\Calls\Pages\EditCall;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Filament\Resources\Calls\Pages\ViewCall;
use App\Filament\Resources\Calls\Schemas\CallForm;
use App\Filament\Resources\Calls\Schemas\CallInfolist;
use App\Filament\Resources\Calls\Tables\CallsTable;
use App\Models\Call;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CallResource extends Resource
{
    protected static ?string $model = Call::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Work;

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CallForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CallInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CallsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalls::route('/'),
            'create' => CreateCall::route('/create'),
            'view' => ViewCall::route('/{record}'),
            'edit' => EditCall::route('/{record}/edit'),
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
