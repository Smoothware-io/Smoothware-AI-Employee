<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Jobs\GenerateCompanyAnalysis;
use App\Models\CompanyAiAnalysis;
use App\Services\Analysis\DisagreementDetector;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only AI analysis for a company (Phase 4). Machine-generated, regenerable,
 * with per-finding confidence — kept visually and structurally distinct from the
 * rep's manual analysis (which is edited on the company form). A "⚠ Disagreement"
 * badge fires when the AI's inferred priority differs from the rep's.
 */
class AiAnalysesRelationManager extends RelationManager
{
    protected static string $relationship = 'aiAnalyses';

    protected static ?string $title = 'AI analysis';

    protected static string|BackedEnum|null $icon = 'heroicon-o-cpu-chip';

    public function table(Table $table): Table
    {
        return $table
            ->poll('5s') // a queued generation appears without a manual refresh
            ->defaultSort('generated_at', 'desc')
            ->columns([
                TextColumn::make('generated_at')
                    ->label('Generated')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                TextColumn::make('inferred_priority')
                    ->label('AI priority')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('overall_confidence')
                    ->label('Confidence')
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? round(((float) $state) * 100).'%' : '—')
                    ->alignCenter(),
                TextColumn::make('disagreement')
                    ->label('Vs. rep')
                    ->badge()
                    ->state(fn (CompanyAiAnalysis $record): string => app(DisagreementDetector::class)
                        ->hasDisagreement($this->getOwnerRecord()->manualAnalysis, $record) ? '⚠ Disagreement' : 'Aligned')
                    ->color(fn (string $state): string => str_contains($state, 'Disagreement') ? 'warning' : 'success'),
                TextColumn::make('recommendations')
                    ->formatStateUsing(fn (?array $state): string => self::summarise($state))
                    ->wrap(),
                TextColumn::make('technical')
                    ->formatStateUsing(fn (?array $state): string => self::summarise($state))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('marketing')
                    ->formatStateUsing(fn (?array $state): string => self::summarise($state))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model_id')
                    ->label('Model')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('generate')
                    ->label('Generate analysis')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->action(function (): void {
                        GenerateCompanyAnalysis::dispatch($this->getOwnerRecord()->getKey());
                        Notification::make()->title('Analysis queued — it will appear shortly')->success()->send();
                    }),
            ])
            ->recordActions([]);
    }

    /**
     * @param  array<int, array{label?: string, assessment?: string, confidence?: float}>|null  $findings
     */
    private static function summarise(?array $findings): string
    {
        if (empty($findings)) {
            return '—';
        }

        return collect($findings)
            ->map(fn (array $f): string => ($f['label'] ?? '?').': '.($f['assessment'] ?? ''))
            ->implode("\n");
    }
}
