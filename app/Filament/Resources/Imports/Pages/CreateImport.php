<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\StageImport;
use Filament\Resources\Pages\CreateRecord;

class CreateImport extends CreateRecord
{
    protected static string $resource = ImportResource::class;

    /** Parse + dedup immediately so the preview is ready on the next screen. */
    protected function afterCreate(): void
    {
        StageImport::dispatchSync($this->record->getKey());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
