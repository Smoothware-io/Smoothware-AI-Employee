<?php

namespace App\Filament\Widgets;

use App\Services\Reporting\AiTrustMetrics;
use App\Services\Reporting\BusinessMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Compliance and automation-health gauges (Phase 8).
 *
 * These are not vanity metrics. `importsMissingLawfulBasis` above zero means
 * personal data was loaded without recording why we may process it — a direct
 * read on GO-LIVE-LEGAL item #2, visible instead of buried in a checklist.
 */
class ComplianceGauges extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Compliance & automation health';

    protected function getStats(): array
    {
        $business = app(BusinessMetrics::class);
        $trust = app(AiTrustMetrics::class);

        $missingBasis = $business->importsMissingLawfulBasis();
        $unjustified = $business->importsWithUnjustifiedBasis();
        $suppressed = $trust->suppressedFollowUps();

        return [
            Stat::make('Imports with no lawful basis', $missingBasis)
                ->description($missingBasis > 0
                    ? 'Personal data loaded without a recorded basis'
                    : 'Every committed import records one')
                ->color($missingBasis > 0 ? 'danger' : 'success'),

            // Looks answered but isn't — a basis carrying an assessment burden
            // with no reasoning recorded.
            Stat::make('Bases needing an assessment', $unjustified)
                ->description($unjustified > 0
                    ? 'Legitimate interest / other with no LIA reference'
                    : 'All justified')
                ->color($unjustified > 0 ? 'warning' : 'success'),

            // A rising number means a rule is misconfigured and generating noise —
            // exactly what the follow-up ledger was built to make visible.
            Stat::make('Follow-ups suppressed', $suppressed)
                ->description($suppressed > 0
                    ? 'Hit the per-company daily cap — check the rules'
                    : 'No rule is over-firing')
                ->color($suppressed > 0 ? 'warning' : 'gray'),

            Stat::make('Contacts with no channel preference', $business->contactsWithoutChannelPreference())
                ->description('Coverage, not a problem — null means never asked')
                ->color('gray'),
        ];
    }
}
