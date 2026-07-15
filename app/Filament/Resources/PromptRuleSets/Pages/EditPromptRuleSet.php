<?php

namespace App\Filament\Resources\PromptRuleSets\Pages;

use App\Filament\Resources\PromptRuleSets\PromptRuleSetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPromptRuleSet extends EditRecord
{
    protected static string $resource = PromptRuleSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
