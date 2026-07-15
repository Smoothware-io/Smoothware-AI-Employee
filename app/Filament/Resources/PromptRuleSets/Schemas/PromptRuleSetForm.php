<?php

namespace App\Filament\Resources\PromptRuleSets\Schemas;

use App\Services\PromptRuleSetService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PromptRuleSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('version')
                    ->numeric()
                    ->required()
                    ->default(fn (): int => app(PromptRuleSetService::class)->nextVersion())
                    ->helperText('Auto-incremented. Publish a new version instead of editing an active one.'),
                Textarea::make('notes')
                    ->helperText('What changed in this version and why.')
                    ->columnSpanFull(),
            ]);
    }
}
