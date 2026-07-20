<?php

namespace App\Filament\Resources\AvailabilityBlocks;

use App\Enums\NavGroup;
use App\Filament\Resources\AvailabilityBlocks\Pages\ManageAvailabilityBlocks;
use App\Models\AvailabilityBlock;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Time the AI must not book into: a holiday, a day off, a conference.
 */
class AvailabilityBlockResource extends Resource
{
    protected static ?string $model = AvailabilityBlock::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Blocked time';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('starts_at')->label('From')->seconds(false)->required(),
            DateTimePicker::make('ends_at')->label('Until')->seconds(false)->required()
                ->after('starts_at'),
            TextInput::make('reason')
                ->label('Reason')
                ->maxLength(255)
                ->helperText('Only for your team — the AI never reads this out.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('From')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('ends_at')->label('Until')->dateTime('d M Y, H:i'),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('creator.name')->label('Added by')->placeholder('—')->toggleable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading('Nothing blocked')
            ->emptyStateDescription('Add a period here and the AI will not offer any time inside it.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAvailabilityBlocks::route('/'),
        ];
    }
}
