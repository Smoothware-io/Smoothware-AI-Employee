<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'asc')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('When')
                    ->dateTime('D d M Y, H:i')
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (Appointment $record): ?string => $record->company?->name),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('location')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('organizer.name')
                    ->label('Organizer')
                    ->placeholder('—'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Google Calendar link-out (v1 — no OAuth sync yet).
                Action::make('googleCalendar')
                    ->label('Add to Google')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->url(fn (Appointment $record): string => $record->googleCalendarUrl())
                    ->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
