<?php

namespace App\Filament\Resources\AiActions\Pages;

use App\Filament\Resources\AiActions\AiActionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiActions extends ListRecords
{
    protected static string $resource = AiActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
