<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewImport extends ViewRecord
{
    protected static string $resource = ImportResource::class;

    // Imports are immutable staged artifacts — reviewed and committed, never edited.
    // (Re-upload to correct a mapping.) No header actions.
}
