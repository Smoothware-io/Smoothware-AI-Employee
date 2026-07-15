<?php

namespace App\Filament\Resources\PromptRuleSets\Pages;

use App\Filament\Resources\PromptRuleSets\PromptRuleSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromptRuleSets extends ListRecords
{
    protected static string $resource = PromptRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
