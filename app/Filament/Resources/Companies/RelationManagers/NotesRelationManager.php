<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\NoteCategory;
use App\Filament\Resources\Notes\Tables\NotesTable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category')
                    ->options(NoteCategory::class)
                    ->default(NoteCategory::Internal->value)
                    ->required(),
                RichEditor::make('body')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return NotesTable::configure($table)
            ->headerActions([CreateAction::make()]);
    }
}
