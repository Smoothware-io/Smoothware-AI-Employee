<?php

namespace App\Services\Reporting;

/**
 * A rate plus the denominator it was computed from (Phase 8).
 *
 * Rates are reported as this object rather than a bare float on purpose: "4%
 * fallback" is meaningless over 5 calls, and a dashboard that prints a confident
 * percentage over a tiny sample actively misleads. Anything below
 * `reporting.min_sample` reports {@see isReliable()} false so the UI can say
 * "too few to tell" instead of inventing precision.
 */
readonly class Metric
{
    public function __construct(
        public int $numerator,
        public int $denominator,
    ) {}

    /** 0.0–1.0, or null when there is nothing to divide by. */
    public function rate(): ?float
    {
        return $this->denominator === 0 ? null : $this->numerator / $this->denominator;
    }

    public function isReliable(): bool
    {
        return $this->denominator >= (int) config('reporting.min_sample', 20);
    }

    /** Human-facing: never a percentage we can't stand behind. */
    public function display(): string
    {
        if ($this->denominator === 0) {
            return '—';
        }

        $percent = number_format(($this->rate() ?? 0) * 100, 1).'%';

        return $this->isReliable()
            ? $percent
            : "{$this->numerator}/{$this->denominator}";
    }

    public function description(): string
    {
        if ($this->denominator === 0) {
            return 'No data yet';
        }

        return $this->isReliable()
            ? "{$this->numerator} of {$this->denominator}"
            : "Only {$this->denominator} observations — too few to read as a rate";
    }
}
