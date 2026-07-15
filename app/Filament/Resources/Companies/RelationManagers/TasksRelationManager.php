<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\TaskType;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')->options(TaskType::class)->required(),
                TextInput::make('title')->required()->maxLength(255),
                Textarea::make('description')->columnSpanFull(),
                Select::make('assigned_to')->relationship('assignee', 'name')->searchable(),
                DateTimePicker::make('due_at')->seconds(false),
            ]);
    }

    public function table(Table $table): Table
    {
        // New tasks start in the default 'open' status; the workflow buttons in
        // TasksTable drive every transition from there.
        return TasksTable::configure($table)
            ->headerActions([CreateAction::make()]);
    }
}
