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
