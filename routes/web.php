<?php

use App\Http\Controllers\InboundCallWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Telephony inbound-call webhook (Phase 3). CSRF-exempt (see bootstrap/app.php).
Route::post('/webhooks/telephony/inbound', InboundCallWebhookController::class)
    ->name('webhooks.telephony.inbound');
