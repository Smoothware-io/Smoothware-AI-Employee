<?php

namespace App\Filament\Resources\FollowUpRules\Pages;

use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFollowUpRule extends CreateRecord
{
    protected static string $resource = FollowUpRuleResource::class;

    /** A rule creates work for other people — record who authored it. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] ??= Auth::id();

        return $data;
    }
}
