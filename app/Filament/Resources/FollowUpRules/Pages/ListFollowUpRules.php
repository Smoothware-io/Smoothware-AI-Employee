<?php

namespace App\Filament\Resources\FollowUpRules\Pages;

use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFollowUpRules extends ListRecords
{
    protected static string $resource = FollowUpRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
