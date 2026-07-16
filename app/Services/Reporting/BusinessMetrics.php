<?php

namespace App\Services\Reporting;

use App\Enums\ImportStatus;
use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\FollowUp;
use App\Models\Import;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Is the pipeline healthy? (Phase 8)
 *
 * Live queries over existing tables — no rollups (see config/reporting.php).
 * Per ARCHITECTURE §9 there is no per-owner filtering: every authenticated user
 * sees the same numbers, because owner_id routes work rather than restricting
 * visibility.
 */
class BusinessMetrics
{
    public function since(?CarbonInterface $from = null): CarbonInterface
    {
        return $from ?? now()->subDays((int) config('reporting.window_days', 30));
    }

    /** @return array<string, int> company count by status */
    public function pipelineByStatus(): array
    {
        return Company::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /** @return array<string, int> company count by provenance (manual/import/ai/system) */
    public function companiesBySource(): array
    {
        return Company::query()
            ->select('source', DB::raw('count(*) as total'))
            ->groupBy('source')
            ->pluck('total', 'source')
            ->all();
    }

    public function newCompanies(?CarbonInterface $from = null): int
    {
        return Company::where('created_at', '>=', $this->since($from))->count();
    }

    public function tasksCreated(?CarbonInterface $from = null): int
    {
        return Task::where('created_at', '>=', $this->since($from))->count();
    }

    public function tasksCompleted(?CarbonInterface $from = null): int
    {
        return Task::where('status', TaskStatus::Completed->value)
            ->where('completed_at', '>=', $this->since($from))
            ->count();
    }

    /** Open work already past its due date — the number a rep actually feels. */
    public function overdueTasks(): int
    {
        return Task::query()->overdue()->count();
    }

    public function unassignedTasks(): int
    {
        return Task::query()->active()->whereNull('assigned_to')->count();
    }

    /** @return array<string, int> follow-ups by status (applied/skipped/...) */
    public function followUpsByStatus(?CarbonInterface $from = null): array
    {
        return FollowUp::where('created_at', '>=', $this->since($from))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * Committed imports carrying no documented lawful basis.
     *
     * This is a compliance gauge, not a vanity metric: it ties directly to
     * GO-LIVE-LEGAL item #2. Any number above zero means personal data was loaded
     * without recording why we may process it.
     */
    public function importsMissingLawfulBasis(): int
    {
        return Import::where('status', ImportStatus::Completed->value)
            ->whereNull('lawful_basis')
            ->count();
    }

    /**
     * Imports whose basis carries an assessment burden but records no reasoning.
     * Mirrors Import::hasUnjustifiedBasis() in aggregate.
     */
    public function importsWithUnjustifiedBasis(): int
    {
        return Import::whereIn('lawful_basis', ['legitimate_interest', 'other'])
            ->where(fn ($q) => $q->whereNull('lawful_basis_notes')->orWhere('lawful_basis_notes', ''))
            ->count();
    }

    /** Contacts with no stated channel preference — coverage, not a problem. */
    public function contactsWithoutChannelPreference(): int
    {
        return DB::table('contacts')
            ->whereNull('archived_at')
            ->whereNull('preferred_channel')
            ->count();
    }
}
