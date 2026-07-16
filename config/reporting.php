<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting (Phase 8)
    |--------------------------------------------------------------------------
    |
    | Metrics are computed with LIVE QUERIES over the existing tables — there is
    | no rollup/aggregate table by design. A pre-aggregated table is a second
    | source of truth that eventually drifts from the events it summarises, and
    | for a dashboard whose whole purpose is measuring AI trustworthiness, a
    | metric that is quietly WRONG is worse than a query that is quietly SLOW.
    |
    | If these queries ever become a real problem, add caching or a materialised
    | view on top — an additive, reversible step. Measure first.
    |
    */

    // Default lookback for the dashboard. No legal weight (unlike the Phase 3
    // retention placeholder) — a sensible default is the right rigour here.
    'window_days' => (int) env('REPORTING_WINDOW_DAYS', 30),

    /*
    | A rate needs a denominator to mean anything: "4% fallback" over 5 calls is
    | noise. Below this many observations the UI shows the raw count and says the
    | sample is too small, rather than printing a confident-looking percentage.
    */
    'min_sample' => (int) env('REPORTING_MIN_SAMPLE', 20),

];
