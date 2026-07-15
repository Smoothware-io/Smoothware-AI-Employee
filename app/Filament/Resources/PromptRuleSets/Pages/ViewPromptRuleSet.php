<?php

namespace App\Filament\Resources\PromptRuleSets\Pages;

use App\Filament\Resources\PromptRuleSets\PromptRuleSetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPromptRuleSet extends ViewRecord
{
    protected static string $resource = PromptRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
