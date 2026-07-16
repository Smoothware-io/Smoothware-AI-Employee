<?php

namespace App\Filament\Resources\FollowUpRules\Pages;

use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFollowUpRule extends EditRecord
{
    protected static string $resource = FollowUpRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
