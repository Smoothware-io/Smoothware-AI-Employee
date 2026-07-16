<?php

namespace App\Filament\Resources\FollowUpRules\Schemas;

use App\Enums\AnalysisPriority;
use App\Enums\AssigneeStrategy;
use App\Enums\CompanyStatus;
use App\Enums\FollowUpTrigger;
use App\Models\Campaign;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FollowUpRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rule')
                    ->description('A rule is a standing instruction: when the trigger happens, a task is created for a human. Nothing is ever sent to the prospect from here.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        Select::make('trigger')
                            ->options(FollowUpTrigger::class)
                            ->required()
                            ->live()
                            ->native(false),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive rules never fire.'),
                    ]),

                Section::make('Only when…')
                    ->description('Leave everything blank to fire for every company. Conditions are combined with AND.')
                    ->columns(2)
                    ->schema([
                        Select::make('conditions.company_status')
                            ->label('Company status is any of')
                            ->options(CompanyStatus::class)
                            ->multiple()
                            ->native(false),
                        Select::make('conditions.campaign_id')
                            ->label('In campaign')
                            // A jsonb condition key, not a foreign key — so options(),
                            // not relationship().
                            ->options(fn (): array => Campaign::orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->native(false),
                        Select::make('conditions.min_ai_priority')
                            ->label('AI priority is at least')
                            ->options(AnalysisPriority::class)
                            ->helperText('Companies with no AI analysis yet will not match.')
                            ->native(false),
                    ]),

                Section::make('The task it creates')
                    ->columns(2)
                    ->schema([
                        TextInput::make('task_title')
                            ->required()
                            ->helperText('Placeholders: {company.name}, {company.domain}, {company.industry}, {company.status}')
                            ->columnSpanFull(),
                        Textarea::make('task_description')
                            ->columnSpanFull(),
                        TextInput::make('delay_minutes')
                            ->label('Due after (minutes)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('0 = due immediately. 1440 = tomorrow.'),
                        Select::make('task_type')
                            ->options([
                                'follow_up' => 'Follow up',
                                'call_back' => 'Call back',
                                'send_proposal' => 'Send proposal',
                                'send_email' => 'Send email',
                            ])
                            ->default('follow_up')
                            ->native(false),
                        Select::make('assignee_strategy')
                            ->label('Assign to')
                            ->options(AssigneeStrategy::class)
                            ->default(AssigneeStrategy::CompanyOwner->value)
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('assignee_id')
                            ->label('Person')
                            ->relationship('assignee', 'name')
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('assignee_strategy') === AssigneeStrategy::SpecificUser->value)
                            ->visible(fn (Get $get): bool => $get('assignee_strategy') === AssigneeStrategy::SpecificUser->value),
                    ]),
            ]);
    }
}
