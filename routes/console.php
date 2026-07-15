<?php

use App\Jobs\PurgeExpiredCallContent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// GDPR retention: destroy call content past its (config-driven) retention window.
Schedule::job(new PurgeExpiredCallContent)->dailyAt('03:00');
