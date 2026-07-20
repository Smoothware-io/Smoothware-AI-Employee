<?php

namespace App\Filament\Resources\CallPersonas\Pages;

use App\Filament\Resources\CallPersonas\CallPersonaResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditCallPersona extends EditRecord
{
    protected static string $resource = CallPersonaResource::class;

    /**
     * Record who changed what the AI says. This text reaches strangers on the
     * phone; "who approved this wording" must always have an answer.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();

        return $data;
    }
}
