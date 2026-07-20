<?php

use App\Http\Controllers\GoogleCalendarOAuthController;
use App\Http\Controllers\InboundCallWebhookController;
use App\Http\Controllers\OpenAiRealtimeWebhookController;
use App\Http\Controllers\Voice\ToolController;
use App\Http\Controllers\Voice\TranscriptController;
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

// The voice tool API (Phase 6 live-voice, ARCHITECTURE §15.6). go-voice calls
// these server-to-server with the shared token — no session, no CSRF, JSON only.
// `POST /api/voice/tool` executes a function the AI called; `/transcript` stores
// the finished transcript on hang-up.
Route::middleware('voice.token')->prefix('api/voice')->group(function () {
    Route::post('/tool', ToolController::class)->name('voice.tool');
    Route::post('/transcript', TranscriptController::class)->name('voice.transcript');
});

// Google Calendar OAuth (Phase 6). Behind auth: connecting a calendar is a
// personal act by a signed-in rep, and an unauthenticated callback would let a
// stranger attach their own calendar to somebody else's profile.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/google/calendar/redirect', [GoogleCalendarOAuthController::class, 'redirect'])
        ->name('google.calendar.redirect');
    Route::get('/google/calendar/callback', [GoogleCalendarOAuthController::class, 'callback'])
        ->name('google.calendar.callback');
});
