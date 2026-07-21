<?php

use App\Jobs\EvaluateTimeBasedFollowUps;
use App\Jobs\PurgeExpiredCallContent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// GDPR retention: destroy call content past its (config-driven) retention window.
Schedule::job(new PurgeExpiredCallContent)->dailyAt('03:00');

// Phase 7: fire NoActivity follow-ups for companies that have gone quiet. Runs
// early so the tasks are waiting when reps start the day. withoutOverlapping is
// belt-and-braces — the evaluator's dedup key already makes a double-run a no-op.
Schedule::job(new EvaluateTimeBasedFollowUps)->dailyAt('06:00')->withoutOverlapping();

// A call whose ending never got reported (crashed gateway, dropped socket) would
// otherwise sit at "In progress" forever, uncountable by every report. Every ten
// minutes is frequent enough that nobody stares at a stuck row for long, and rare
// enough to be free.
Schedule::command('calls:close-stale')->everyTenMinutes()->withoutOverlapping();

// The campaign heartbeat. Ticks every minute but places at most ONE call per
// campaign, and only when that campaign's own pace says it is due — so the
// frequency here is resolution, not speed. withoutOverlapping because two
// concurrent ticks would both see the same "next" company and ring it twice.
Schedule::command('campaigns:tick')->everyMinute()->withoutOverlapping();
