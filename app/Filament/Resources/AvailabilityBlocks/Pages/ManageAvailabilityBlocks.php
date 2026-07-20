<?php

namespace App\Filament\Resources\AvailabilityBlocks\Pages;

use App\Filament\Resources\AvailabilityBlocks\AvailabilityBlockResource;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageAvailabilityBlocks extends ManageRecords
{
    protected static string $resource = AvailabilityBlockResource::class;

    /** Blocking time is a decision someone made; record who. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
