<?php

namespace App\Filament\Resources\CallPersonas\Pages;

use App\Enums\CallDirection;
use App\Filament\Resources\CallPersonas\CallPersonaResource;
use App\Models\CallPersona;
use Filament\Resources\Pages\ListRecords;

class ListCallPersonas extends ListRecords
{
    protected static string $resource = CallPersonaResource::class;

    /**
     * Seed the two personas the system actually has, on first view.
     *
     * There is no "create" action deliberately: there are exactly two directions,
     * and a third persona would be a row the AI never reads. Materialising the
     * defaults here means the page is never an empty table with nothing to click,
     * and what a user edits is visibly the same text the AI was already using.
     */
    public function mount(): void
    {
        parent::mount();

        foreach (CallDirection::cases() as $direction) {
            CallPersona::firstOrCreate(
                ['direction' => $direction->value],
                ['role' => CallPersona::defaultRole($direction)],
            );
        }
    }
}
