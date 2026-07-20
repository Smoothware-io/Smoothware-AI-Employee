<?php

namespace App\Filament\Resources\AvailabilityRules;

use App\Enums\NavGroup;
use App\Filament\Resources\AvailabilityRules\Pages\ManageAvailabilityRules;
use App\Models\AvailabilityRule;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The hours in which the AI may book — "Mondays 09:00–17:00".
 *
 * Kept separate from one-off blocks: "we are closed on 24 December" is not a
 * fact about Tuesdays, and storing it as one would close every Tuesday next year.
 */
class AvailabilityRuleResource extends Resource
{
    protected static ?string $model = AvailabilityRule::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Working hours';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('weekday')
                ->label('Day')
                ->options([
                    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                    5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
                ])
                ->required(),

            TimePicker::make('starts_at')->label('From')->seconds(false)->required(),
            TimePicker::make('ends_at')->label('Until')->seconds(false)->required()
                ->after('starts_at'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Switch off to stop the AI booking on this day without deleting the rule.'),

            // user_id is intentionally absent from the form. The column exists for
            // per-rep availability later; exposing it now would invite half-set
            // data whose meaning changes the day that feature lands.
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('weekday')
            ->columns([
                TextColumn::make('weekday')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => AvailabilityRule::weekdayName($state))
                    ->sortable(),
                TextColumn::make('starts_at')->label('From'),
                TextColumn::make('ends_at')->label('Until'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->emptyStateHeading('No working hours set')
            ->emptyStateDescription('Until you add any, the AI uses the default Monday–Friday business hours.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAvailabilityRules::route('/'),
        ];
    }
}
