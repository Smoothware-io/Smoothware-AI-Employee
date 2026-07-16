<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Filament\Resources\Suppressions\SuppressionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppressions extends ListRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add do-not-contact'),
        ];
    }
}
