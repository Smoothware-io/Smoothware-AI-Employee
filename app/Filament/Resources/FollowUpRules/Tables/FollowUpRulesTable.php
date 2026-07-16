<?php

namespace App\Filament\Resources\FollowUpRules\Tables;

use App\Enums\FollowUpTrigger;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FollowUpRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable()->wrap(),
                TextColumn::make('trigger')->badge(),
                TextColumn::make('task_title')->label('Creates')->limit(40)->toggleable(),
                TextColumn::make('assignee_strategy')->label('Assign to')->badge()->color('gray'),
                TextColumn::make('follow_ups_count')
                    ->counts('followUps')
                    ->label('Fired')
                    ->alignCenter(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('creator.name')->label('Author')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('trigger')->options(FollowUpTrigger::class),
                TernaryFilter::make('is_active')->label('Active'),
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
