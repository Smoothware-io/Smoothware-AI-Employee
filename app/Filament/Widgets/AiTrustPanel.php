<?php

namespace App\Filament\Widgets;

use App\Services\Reporting\AiTrustMetrics;
use App\Services\Reporting\Metric;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Is the AI trustworthy? (Phase 8) — the instrument panel behind principle #4,
 * "start human-in-the-loop, earn autonomy".
 *
 * Every rate here shows its DENOMINATOR in the description, and
 * {@see Metric} refuses to render a percentage below the
 * configured sample floor. A confident "4%" over five calls is worse than no
 * number at all, because someone will act on it.
 */
class AiTrustPanel extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'AI trust';

    protected ?string $description = 'Whether the AI has earned more autonomy — read the denominators, not just the rates.';

    protected function getStats(): array
    {
        $m = app(AiTrustMetrics::class);

        $fallback = $m->fallbackRate();
        $rejection = $m->rejectionRate();
        $disagreement = $m->disagreementRate();
        $confidence = $m->averageConfidence();
        $latency = $m->medianLatencyMs();

        return [
            // High is NOT automatically bad: refusing to improvise without
            // grounding is the system working. Near-zero deserves as much
            // suspicion as near-one.
            Stat::make('Handed to a human', $fallback->display())
                ->description($fallback->description())
                ->color($fallback->isReliable() ? 'info' : 'gray'),

            Stat::make('Rejected by reviewers', $rejection->display())
                ->description($rejection->description().' reviewed')
                ->color(match (true) {
                    ! $rejection->isReliable() => 'gray',
                    ($rejection->rate() ?? 0) > 0.25 => 'danger',
                    default => 'success',
                }),

            Stat::make('Awaiting review', $m->pendingReviewCount())
                ->description('Drafts a human has not judged yet')
                ->color($m->pendingReviewCount() > 0 ? 'warning' : 'gray'),

            // Where the rep's judgment visibly overrides the AI (Phase 4).
            Stat::make('AI/human disagreement', $disagreement->display())
                ->description($disagreement->description().' with both analyses')
                ->color($disagreement->isReliable() ? 'warning' : 'gray'),

            Stat::make('Avg confidence', $confidence === null ? '—' : number_format($confidence * 100, 1).'%')
                ->description($confidence === null ? 'No AI actions yet' : 'Self-reported by the model')
                ->color('gray'),

            // Median, not mean — one timeout would skew a mean beyond use.
            Stat::make('Median latency', $latency === null ? '—' : number_format($latency).' ms')
                ->description($latency === null ? 'No runs yet' : 'Median of AI runs in window')
                ->color('gray'),
        ];
    }
}
