<?php

namespace App\Filament\Resources\AiActions\Pages;

use App\Filament\Resources\AiActions\AiActionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAiAction extends ViewRecord
{
    protected static string $resource = AiActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
