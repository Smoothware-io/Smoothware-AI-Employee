<?php

namespace App\Filament\Resources\CallPersonas;

use App\Enums\CallDirection;
use App\Enums\NavGroup;
use App\Enums\PersonaPreset;
use App\Filament\Resources\CallPersonas\Pages\EditCallPersona;
use App\Filament\Resources\CallPersonas\Pages\ListCallPersonas;
use App\Models\CallPersona;
use App\Services\Voice\PersonaWriter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
// Schemas\...\Get, NOT Forms\Get: inside a SCHEMA action Filament injects the
// schema utility, and the wrong type hint throws the moment the button is
// pressed — which renders fine and fails only on click.
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Lets a human edit WHO the AI is on a call, without a deploy.
 *
 * Scope is deliberately narrow. The role and the goal are business decisions and
 * belong in a form. The Art. 50 disclosure, the hard limits, and the tool
 * instructions are NOT here and must not be: a live call has no review queue and
 * no undo, so putting a delete button next to legal compliance and next to the
 * grounding contract would make one careless edit unrecoverable in a way no
 * amount of auditing fixes afterwards.
 */
class CallPersonaResource extends Resource
{
    protected static ?string $model = CallPersona::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::TeachTheAi;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'What the AI is';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'direction';

    /**
     * `direction` is an enum cast, so the default implementation hands the page
     * title a CallDirection where a string is required and the whole page dies
     * with a TypeError. Return the human label instead.
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->direction->getLabel() ?? static::getModelLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // NOT "Role". Shield's Roles resource means WHO MAY USE THIS APP;
            // this means WHAT THE AI IS. Same word, unrelated things, one
            // sidebar — so this one avoids the word entirely.
            Section::make('How the AI behaves on this call')
                ->description('What the AI is on this kind of call. Written in the language you want it to think in.')
                ->schema([
                    Select::make('direction')
                        ->options(CallDirection::class)
                        ->required()
                        // One persona per direction: two "inbound" rows would leave
                        // a genuine question about which one the AI is using.
                        ->unique(ignoreRecord: true)
                        ->helperText('Inbound = they call us. Outbound = we call them.'),

                    Select::make('preset')
                        ->label('What kind of call is this?')
                        ->options(PersonaPreset::class)
                        // Not persisted: this is a BRIEF for the writer, not a
                        // setting. Once the text exists the preset that produced
                        // it stops mattering, and storing it would invite someone
                        // to edit the text and leave a preset that no longer
                        // describes it.
                        ->dehydrated(false)
                        ->helperText('Pick one, then press Draft. You can edit everything afterwards.'),

                    SchemaActions::make([
                        Action::make('draft')
                            ->label('Draft with AI')
                            ->icon('heroicon-o-sparkles')
                            ->color('primary')
                            ->visible(fn (): bool => app(PersonaWriter::class)->configured())
                            ->action(function (Get $get, Set $set): void {
                                // Filament hands back the ENUM when the select is
                                // enum-backed and a raw string when it is not, so
                                // accept both. Casting an enum object with (string)
                                // is a fatal error, and one only a click finds.
                                $rawPreset = $get('preset');
                                $preset = $rawPreset instanceof PersonaPreset
                                    ? $rawPreset
                                    : PersonaPreset::tryFrom((string) $rawPreset);

                                $rawDirection = $get('direction');
                                $direction = $rawDirection instanceof CallDirection
                                    ? $rawDirection
                                    : CallDirection::tryFrom((string) $rawDirection);

                                if ($preset === null || $direction === null) {
                                    Notification::make()
                                        ->title('Pick a direction and a call type first')
                                        ->warning()->send();

                                    return;
                                }

                                try {
                                    $draft = app(PersonaWriter::class)->draft($preset, $direction);
                                } catch (\Throwable $e) {
                                    Notification::make()
                                        ->title('Could not draft instructions')
                                        ->body($e->getMessage())
                                        ->danger()->persistent()->send();

                                    return;
                                }

                                // Filled into the FORM, not saved. A human reads it
                                // and presses save; nothing generated here reaches a
                                // caller unread (§14).
                                $set('role', $draft['role']);
                                $set('goal', $draft['goal']);

                                Notification::make()
                                    ->title('Draft ready — read it before saving')
                                    ->body('Written from your knowledge base. Check it says what you want, then save.')
                                    ->success()->send();
                            }),
                    ])->columnSpanFull(),

                    Textarea::make('role')
                        ->label('Who the AI is')
                        ->required()
                        ->rows(6)
                        ->columnSpanFull()
                        ->helperText('Who the AI is and how it should behave. An AI that answers must not announce a call; an AI that calls must not ask "how can I help you?".'),

                    Textarea::make('goal')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('Optional. What a good call achieves — for example: understand the need and book an intro meeting.'),
                ]),

            Section::make('What you cannot change here')
                ->description('These are safety invariants, kept in code on purpose.')
                ->collapsed()
                ->schema([
                    Text::make(
                        'The AI disclosure (EU AI Act Art. 50), the hard limits (never invent facts, never quote prices, '
                        .'honour a do-not-call request immediately), and the booking-tool instructions are not editable. '
                        .'They protect the caller and the company, and a live call cannot be undone.'
                    ),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('direction')->badge(),
                TextColumn::make('role')->label('Who the AI is')->limit(80)->wrap(),
                TextColumn::make('goal')->limit(60)->placeholder('—')->toggleable(),
                TextColumn::make('editor.name')->label('Last edited by')->placeholder('—'),
                TextColumn::make('updated_at')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCallPersonas::route('/'),
            'edit' => EditCallPersona::route('/{record}/edit'),
        ];
    }
}
