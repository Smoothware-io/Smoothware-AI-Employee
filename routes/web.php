<?php

use App\Http\Controllers\InboundCallWebhookController;
use App\Http\Controllers\OpenAiRealtimeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Telephony inbound-call webhook (Phase 3). CSRF-exempt (see bootstrap/app.php).
Route::post('/webhooks/telephony/inbound', InboundCallWebhookController::class)
    ->name('webhooks.telephony.inbound');

// OpenAI Realtime asks here what the AI should be, the moment SIP arrives
// (Phase 6). Nothing answers the phone until this responds — see the controller.
// Must be publicly reachable; signature-verified, never open.
Route::post('/webhooks/openai/realtime', OpenAiRealtimeWebhookController::class)
    ->name('webhooks.openai.realtime');
