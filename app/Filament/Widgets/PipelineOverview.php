<?php

namespace App\Filament\Widgets;

use App\Enums\CompanyStatus;
use App\Services\Reporting\BusinessMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Is the pipeline healthy? (Phase 8)
 *
 * Per ARCHITECTURE §9 there is no per-owner filtering: every authenticated user
 * sees the same numbers.
 */
class PipelineOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Pipeline';

    protected function getStats(): array
    {
        $metrics = app(BusinessMetrics::class);
        $byStatus = $metrics->pipelineByStatus();
        $window = (int) config('reporting.window_days', 30);

        return [
            Stat::make('Leads', $byStatus[CompanyStatus::Lead->value] ?? 0)
                ->description("{$metrics->newCompanies()} added in the last {$window} days")
                ->color('info'),

            Stat::make('Qualified', $byStatus[CompanyStatus::Qualified->value] ?? 0)
                ->color('warning'),

            Stat::make('Customers', $byStatus[CompanyStatus::Customer->value] ?? 0)
                ->color('success'),

            Stat::make('Overdue tasks', $metrics->overdueTasks())
                ->description($metrics->unassignedTasks().' unassigned')
                ->color($metrics->overdueTasks() > 0 ? 'danger' : 'gray'),
        ];
    }
}
