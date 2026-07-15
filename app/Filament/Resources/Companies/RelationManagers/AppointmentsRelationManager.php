<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required()->maxLength(255),
                DateTimePicker::make('starts_at')->seconds(false)->required(),
                DateTimePicker::make('ends_at')->seconds(false),
                TextInput::make('location')->maxLength(255),
                Select::make('status')
                    ->options(AppointmentStatus::class)
                    ->default(AppointmentStatus::Scheduled->value)
                    ->required(),
                Select::make('contact_id')->relationship('contact', 'full_name')->searchable(),
                Textarea::make('description')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return AppointmentsTable::configure($table)
            ->headerActions([CreateAction::make()]);
    }
}
