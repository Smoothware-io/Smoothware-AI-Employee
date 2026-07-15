<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Filament\Resources\Calls\Tables\CallsTable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class CallsRelationManager extends RelationManager
{
    protected static string $relationship = 'calls';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('direction')->options(CallDirection::class)->required(),
                Select::make('status')->options(CallStatus::class)->required(),
                TextInput::make('from_number')->tel()->maxLength(255),
                TextInput::make('to_number')->tel()->maxLength(255),
                DateTimePicker::make('started_at')->seconds(false),
                DateTimePicker::make('ended_at')->seconds(false),
                TextInput::make('duration_seconds')->numeric()->label('Duration (seconds)'),
                Textarea::make('summary')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return CallsTable::configure($table)
            ->headerActions([CreateAction::make()]);
    }
}
