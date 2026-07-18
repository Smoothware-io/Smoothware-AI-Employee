<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The go-voice control gateway (Phase 6 live-voice, ARCHITECTURE §15)
    |--------------------------------------------------------------------------
    |
    | go-voice rides OpenAI's control WebSocket for each call and turns the AI's
    | function calls into CRM actions. Laravel is the brain (declares the tools,
    | implements them); go-voice is the hands (executes whatever the AI calls).
    |
    | The hand-off is CONDITIONAL on `gateway_url` being set:
    |   - set   -> Laravel declares tools in the accept payload and hands the call
    |              to go-voice, which can then execute them. The AI has hands.
    |   - unset -> no tools are declared and the PHP ObserveRealtimeCall observer
    |              runs instead (transcript only, no actions). The AI can still
    |              talk; it just cannot do. Nothing breaks if the gateway is down.
    |
    | This means deploying the gateway is a real cutover you control, not a
    | flag-day where a missing service silently kills every call.
    */

    'gateway_url' => env('VOICE_GATEWAY_URL'),

    /*
    | Shared secret, both directions: Laravel presents it to hand off a call, the
    | gateway presents it back on every tool call. MUST match go-voice's
    | VOICE_SERVICE_TOKEN. A tool call writes to the CRM and could book a meeting
    | in a stranger's calendar — it must not be forgeable.
    */
    'service_token' => env('VOICE_SERVICE_TOKEN'),

    /*
    | Business hours the availability tool offers, in the app timezone. Kept in
    | config, not hard-coded, because "when can the AI book" is a business policy
    | a manager may want to change without a deploy.
    */
    'booking' => [
        'open_hour' => (int) env('VOICE_BOOKING_OPEN_HOUR', 9),
        'close_hour' => (int) env('VOICE_BOOKING_CLOSE_HOUR', 17),
        'slot_minutes' => (int) env('VOICE_BOOKING_SLOT_MINUTES', 30),
        // How many days ahead the AI may offer, so it cannot book into next year.
        'horizon_days' => (int) env('VOICE_BOOKING_HORIZON_DAYS', 14),
    ],

];
