<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound calling (Phase 6)
    |--------------------------------------------------------------------------
    |
    | The AI places a call. This is the most consequential thing this system can
    | do, and the only feature where a mistake reaches a stranger's phone rather
    | than a colleague's screen.
    |
    | Read GO-LIVE-LEGAL.md item #3 before enabling. In short: calling a list you
    | did not collect yourself is telemarketing; many Dutch small businesses are
    | ZZP'ers who may count as CONSUMERS, where cold-calling is prohibited rather
    | than merely regulated. That is a determination for counsel, not for config.
    |
    | Every gate below FAILS CLOSED. Enabling is a deliberate act by a human who
    | is accountable for it — never a default, never an accident.
    |
    */

    // The master switch. Nothing dials while this is false, whatever else is set.
    'enabled' => (bool) env('OUTBOUND_ENABLED', false),

    /*
    | EU AI Act Art. 50: a person must be told they are talking to a machine.
    | Prepended to the AI's instructions as the first thing it says — not buried,
    | not on request. No disclosure configured = no calls, by design.
    |
    | "KAN worden opgenomen", not "WORDT opgenomen". The AI must not state as fact
    | something we have not built: audio capture is unproven, and a disclosure that
    | over-claims is a lie told in the same breath as "I am an AI". If recording
    | becomes certain, tighten this to the definite form — an honest promise is
    | worth more than a vague one, but a false one is worth less than nothing.
    */
    'disclosure' => env(
        'OUTBOUND_DISCLOSURE',
        'Je spreekt met een AI-assistent van Smoothware. Dit gesprek kan worden opgenomen. Schikt het u om even te praten?',
    ),

    /*
    | Screening against the national opt-out register (recht van verzet).
    |
    | 'none' is NOT "skip screening" — it means no screener is implemented yet, so
    | the dialler refuses. Which register(s), and how often to re-screen, is the
    | open question with counsel (GO-LIVE-LEGAL Q2c). Until that is answered and a
    | screener exists, this cannot be satisfied by editing config.
    */
    'register_screening' => env('OUTBOUND_REGISTER_SCREENING', 'none'),

    /*
    | Allow dialling before a register screener exists. Deliberately separate from
    | `enabled`, deliberately ugly to type, and deliberately logged on every call.
    | Setting this is a statement that a human has taken responsibility for the
    | screening question by other means.
    */
    'allow_without_register_screening' => (bool) env('OUTBOUND_ALLOW_WITHOUT_REGISTER_SCREENING', false),

    /*
    | Numbers the dialler may call regardless of the gates — your own mobile, a
    | colleague's, a friend who agreed to be a test subject. This is how you prove
    | the pipe without touching a prospect.
    |
    | When set, the dialler will call ONLY these numbers and refuse everything
    | else. That is the safest possible way to test a system that makes phone
    | calls: it cannot reach a stranger by mistake.
    */
    'test_numbers' => array_values(array_filter(
        explode(',', (string) env('OUTBOUND_TEST_NUMBERS', '')),
    )),

    // Belt and braces against a runaway loop dialling the same list all day.
    'max_calls_per_day' => (int) env('OUTBOUND_MAX_CALLS_PER_DAY', 50),

    /*
    | OpenAI Realtime — the voice. Sonetel is the carrier; OpenAI only ever
    | RECEIVES SIP, which is why outbound works by bridging two legs rather than
    | by asking OpenAI to dial.
    */
    'openai' => [
        'project_id' => env('OPENAI_PROJECT_ID'),
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
        'voice' => env('OPENAI_REALTIME_VOICE', 'alloy'),
        'sip_host' => env('OPENAI_SIP_HOST', 'sip.api.openai.com'),
        // OpenAI signs every webhook; we verify it. An unsigned "incoming call"
        // is someone else spending your money.
        'webhook_secret' => env('OPENAI_WEBHOOK_SECRET'),
    ],

    /*
    | Sonetel credentials are deliberately NOT here. Each rep connects their own
    | account from their profile (sonetel_accounts), because the caller ID a
    | prospect sees should belong to the person accountable for the call — and a
    | token in .env expires after 24 hours, which would stop every call dead the
    | next morning with no warning.
    |
    | Endpoints live in SonetelTokenService / SonetelDialer, verified against
    | Sonetel's own SDK. Note auth and the API are on DIFFERENT hosts.
    */

];
