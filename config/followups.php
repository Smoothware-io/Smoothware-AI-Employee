<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Follow-up automation (Phase 7)
    |--------------------------------------------------------------------------
    |
    | Internal task automation only: rules create TASKS for humans. Nothing is
    | sent to a prospect from here. Auto-sending outbound email is deliberately
    | out of scope — it opens the direct-marketing / ePrivacy surface that
    | GO-LIVE-LEGAL.md tracks, and Phase 7 was sequenced ahead of Phase 6
    | precisely because it carries no such gate.
    |
    */

    // How long a company must be quiet before a NoActivity rule fires.
    // No legal weight (unlike the Phase 3 retention placeholder) — a sensible
    // default is the right amount of rigour here.
    'no_activity_days' => (int) env('FOLLOWUPS_NO_ACTIVITY_DAYS', 14),

    // Spam guard: the most follow-ups any single company may generate in a day.
    // A badly-written rule should not be able to bury a rep under 200 tasks.
    // Follow-ups beyond the cap are recorded as `skipped`, never silently dropped.
    'max_per_company_per_day' => (int) env('FOLLOWUPS_MAX_PER_COMPANY_PER_DAY', 5),

    // AI-suggested follow-ups (as opposed to human-authored rules). OFF at
    // launch: Phase 7 earns its way in on rules alone, the same way the Phase 3
    // receptionist did. The schema already carries the provenance columns, but
    // the suggester itself is not built — see ARCHITECTURE.
    'ai_suggestions' => (bool) env('FOLLOWUPS_AI_SUGGESTIONS', false),

];
