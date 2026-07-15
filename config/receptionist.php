<?php

return [

    /*
    | Grounding: the AI may only answer from retrieved knowledge-base content.
    | If the best retrieval score is below the threshold, the draft is flagged
    | fallback_to_human and the AI does not improvise (enforced in the
    | orchestration layer, not just the prompt).
    */
    'grounding' => [
        'min_score' => (float) env('RECEPTIONIST_GROUNDING_MIN_SCORE', 0.15),
        'top_k' => (int) env('RECEPTIONIST_TOP_K', 5),
    ],

    /*
    | Company dedup match confidence: at/above this we treat the caller's company
    | as an existing record; below it we propose a new company (for human review).
    */
    'match' => [
        'min_confidence' => (float) env('RECEPTIONIST_MATCH_MIN_CONFIDENCE', 0.6),
    ],

    /*
    | Call recording & retention.
    | ⚠ PLACEHOLDER VALUES — legal/compliance sign-off required before going live
    | with real calls (NL/EU GDPR). These are configurable on purpose so legal
    | can set the real retention period and disclosure wording WITHOUT a code
    | change. See ARCHITECTURE.md §8.
    */
    'recording' => [
        'retention_days' => (int) env('CALL_RETENTION_DAYS', 90),
        'disclosure' => env(
            'CALL_DISCLOSURE',
            'This call may be recorded for quality and training purposes.'
        ),
    ],

    /*
    | Inbound webhook shared secret (placeholder). Replace with real Sonetel
    | signature verification before go-live — see SonetelProvider / the webhook
    | controller. Null = accept unauthenticated (dev only).
    */
    'webhook_secret' => env('TELEPHONY_WEBHOOK_SECRET'),

    /*
    | Swappable providers. Everything external is behind an interface with an
    | offline fake, so the pipeline is built and tested with no vendor account.
    */
    'drivers' => [
        'telephony' => env('TELEPHONY_DRIVER', 'fake'),        // fake | sonetel
        'transcription' => env('TRANSCRIPTION_DRIVER', 'fake'), // fake | (real: TBD)
        'llm' => env('LLM_DRIVER', 'fake'),                     // fake | claude
    ],
];
