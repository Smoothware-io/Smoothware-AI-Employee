<?php

namespace App\Filament\Resources\Notes\Tables;

use App\Enums\NoteCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class NotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                TextColumn::make('category')
                    ->badge(),
                TextColumn::make('body')
                    ->label('Note')
                    ->formatStateUsing(fn (?string $state): string => Str::limit(strip_tags((string) $state), 80))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('author.name')
                    ->label('Author')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(collect(NoteCategory::cases())->mapWithKeys(fn (NoteCategory $c) => [$c->value => $c->getLabel()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
