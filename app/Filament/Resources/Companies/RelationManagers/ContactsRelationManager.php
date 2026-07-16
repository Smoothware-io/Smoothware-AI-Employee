<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\PreferredChannel;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $recordTitleAttribute = 'full_name';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')->required()->maxLength(255),
                TextInput::make('last_name')->maxLength(255),
                TextInput::make('job_title')->maxLength(255),
                Toggle::make('is_decision_maker'),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(255),
                Select::make('preferred_channel')
                    ->label('Prefers to be contacted by')
                    ->options(PreferredChannel::class)
                    ->placeholder('Not stated')
                    ->native(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return ContactsTable::configure($table)
            ->headerActions([CreateAction::make()]);
    }
}
