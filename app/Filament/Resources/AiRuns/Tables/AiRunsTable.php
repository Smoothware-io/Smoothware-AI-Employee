<?php

namespace App\Filament\Resources\AiRuns\Tables;

use App\Models\AiRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiRunsTable
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
                TextColumn::make('kind')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('grounded')
                    ->boolean()
                    ->alignCenter(),
                IconColumn::make('fallback_to_human')
                    ->label('Fallback')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->alignCenter(),
                TextColumn::make('model_id')
                    ->label('Model')
                    ->toggleable(),
                TextColumn::make('latency_ms')
                    ->label('Latency')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state} ms" : '—')
                    ->alignEnd(),
                TextColumn::make('tokens')
                    ->label('Tokens (in/out)')
                    ->state(fn (AiRun $record): string => "{$record->input_tokens}/{$record->output_tokens}")
                    ->alignEnd(),
                TextColumn::make('context_version')
                    ->label('Context')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
