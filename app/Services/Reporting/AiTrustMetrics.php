<?php

namespace App\Services\Reporting;

use App\Enums\AiActionStatus;
use App\Models\AiAction;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\FollowUp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Is the AI trustworthy? (Phase 8)
 *
 * This is the instrument panel behind principle #4 — "start human-in-the-loop,
 * earn autonomy". You cannot responsibly decide the receptionist is good enough
 * to auto-apply without knowing how often it falls back and how often humans
 * reject what it proposes. These numbers are what make that an evidence-based
 * decision rather than a vibe.
 *
 * All live queries over existing tables — no rollups (see config/reporting.php).
 * Rates return {@see Metric} so the denominator travels with them.
 */
class AiTrustMetrics
{
    public function since(?CarbonInterface $from = null): CarbonInterface
    {
        return $from ?? now()->subDays((int) config('reporting.window_days', 30));
    }

    /**
     * How often the receptionist handed a call to a human instead of drafting.
     * HIGH is not automatically bad — refusing to improvise when the KB has no
     * grounding is the system working. A rate near zero deserves as much
     * suspicion as one near one.
     */
    public function fallbackRate(?CarbonInterface $from = null): Metric
    {
        $runs = AiRun::where('kind', 'receptionist')->where('created_at', '>=', $this->since($from));

        return new Metric(
            numerator: (clone $runs)->where('fallback_to_human', true)->count(),
            denominator: $runs->count(),
        );
    }

    /** Runs that actually retrieved grounding, out of all receptionist runs. */
    public function groundedRate(?CarbonInterface $from = null): Metric
    {
        $runs = AiRun::where('kind', 'receptionist')->where('created_at', '>=', $this->since($from));

        return new Metric(
            numerator: (clone $runs)->where('grounded', true)->count(),
            denominator: $runs->count(),
        );
    }

    /**
     * Of the AI actions a human has actually reviewed, how many were rejected?
     *
     * The denominator is REVIEWED actions, not all actions — pending drafts
     * haven't been judged yet, and counting them would drag the rate toward zero
     * and make the AI look better the more of a backlog we have.
     */
    public function rejectionRate(?CarbonInterface $from = null): Metric
    {
        $reviewed = AiAction::whereNotNull('reviewed_at')
            ->where('created_at', '>=', $this->since($from))
            ->whereIn('status', [AiActionStatus::Approved->value, AiActionStatus::Rejected->value]);

        return new Metric(
            numerator: (clone $reviewed)->where('status', AiActionStatus::Rejected->value)->count(),
            denominator: $reviewed->count(),
        );
    }

    /** Drafts still waiting on a human — queue depth, not a rate. */
    public function pendingReviewCount(): int
    {
        return AiAction::where('status', AiActionStatus::Draft->value)->count();
    }

    /**
     * How often the AI's inferred priority disagrees with the rep's manual one
     * (Phase 4). Only companies where BOTH exist can disagree — a company with
     * no manual analysis isn't agreement, it's silence.
     */
    public function disagreementRate(): Metric
    {
        $comparable = Company::query()
            ->whereHas('manualAnalysis')
            ->whereHas('latestAiAnalysis');

        $disagreeing = (clone $comparable)->whereExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('company_manual_analyses as cma')
                ->join('company_ai_analyses as caa', 'caa.company_id', '=', 'cma.company_id')
                ->whereColumn('cma.company_id', 'companies.id')
                ->whereColumn('cma.priority', '!=', 'caa.inferred_priority')
                ->whereNotNull('cma.priority')
                ->whereNotNull('caa.inferred_priority');
        });

        return new Metric(
            numerator: $disagreeing->count(),
            denominator: $comparable->count(),
        );
    }

    /** Mean confidence of AI actions in the window — null when there are none. */
    public function averageConfidence(?CarbonInterface $from = null): ?float
    {
        $avg = AiAction::where('created_at', '>=', $this->since($from))
            ->whereNotNull('confidence_score')
            ->avg('confidence_score');

        return $avg === null ? null : (float) $avg;
    }

    /** Median latency is more honest than mean here — one timeout skews a mean. */
    public function medianLatencyMs(?CarbonInterface $from = null): ?int
    {
        $latencies = AiRun::where('created_at', '>=', $this->since($from))
            ->whereNotNull('latency_ms')
            ->orderBy('latency_ms')
            ->pluck('latency_ms');

        if ($latencies->isEmpty()) {
            return null;
        }

        return (int) $latencies->get((int) floor(($latencies->count() - 1) / 2));
    }

    public function totalCost(?CarbonInterface $from = null): float
    {
        return (float) AiRun::where('created_at', '>=', $this->since($from))->sum('cost');
    }

    /** @return array<string, int> run count by kind */
    public function runsByKind(?CarbonInterface $from = null): array
    {
        return AiRun::where('created_at', '>=', $this->since($from))
            ->select('kind', DB::raw('count(*) as total'))
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->all();
    }

    /**
     * Follow-ups suppressed by the per-company cap. A rising number means a rule
     * is misconfigured and quietly generating noise, which is exactly the thing
     * the ledger was built to make visible.
     */
    public function suppressedFollowUps(?CarbonInterface $from = null): int
    {
        return FollowUp::where('status', 'skipped')
            ->where('created_at', '>=', $this->since($from))
            ->count();
    }
}
