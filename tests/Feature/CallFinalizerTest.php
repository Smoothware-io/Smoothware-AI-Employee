<?php

use App\Enums\CallStatus;
use App\Models\Call;
use App\Services\Voice\CallFinalizer;

use function Pest\Laravel\artisan;

/**
 * Every call ever placed sat at "In progress" forever, because nothing closed
 * one. A call with no outcome cannot be counted by any report and looks
 * identical to a conversation happening right now.
 */
function liveCall(array $overrides = []): Call
{
    return Call::create(array_merge([
        'direction' => 'inbound',
        'status' => CallStatus::InProgress->value,
        'external_provider' => 'openai-realtime',
        'external_id' => 'rtc_live_'.uniqid(),
        'started_at' => now()->subMinutes(3),
    ], $overrides));
}

it('closes a call with an end time, duration and outcome', function () {
    $call = liveCall(['started_at' => now()->subSeconds(90)]);

    app(CallFinalizer::class)->close($call);

    expect($call->fresh())
        ->status->toBe(CallStatus::Completed)
        ->ended_at->not->toBeNull()
        ->and($call->fresh()->duration_seconds)->toBeGreaterThanOrEqual(89)
        ->and($call->fresh()->duration_seconds)->toBeLessThanOrEqual(92);
});

it('never overwrites a call that is already closed', function () {
    // go-voice may retry, and the stale sweeper may race it. Neither may move an
    // end time that is already recorded.
    $call = liveCall();
    app(CallFinalizer::class)->close($call);
    $firstEnding = $call->fresh()->ended_at;

    $this->travel(5)->minutes();
    app(CallFinalizer::class)->close($call->fresh(), CallStatus::Failed);

    expect($call->fresh()->ended_at->timestamp)->toBe($firstEnding->timestamp)
        ->and($call->fresh()->status)->toBe(CallStatus::Completed);
});

it('closes the call when go-voice reports the transcript', function () {
    config(['voice.service_token' => 'test-secret-token']);
    $call = liveCall(['external_id' => 'rtc_hangup']);

    $this->postJson('/api/voice/transcript', [
        'call_id' => 'rtc_hangup',
        'transcript' => "AI: Goedemiddag.\nCALLER: Hoi.",
    ], ['Authorization' => 'Bearer test-secret-token'])->assertOk();

    expect($call->fresh())
        ->status->toBe(CallStatus::Completed)
        ->ended_at->not->toBeNull();
});

it('sweeps up calls whose ending was never reported', function () {
    // The gateway crashed, or the socket died without a final event.
    $abandoned = liveCall(['started_at' => now()->subHours(2)]);

    artisan('calls:close-stale')->assertSuccessful();

    // FAILED, not completed: we do not know how that call ended, and recording a
    // guess as a success would corrupt the one number the business cares about.
    expect($abandoned->fresh())
        ->status->toBe(CallStatus::Failed)
        ->ended_at->not->toBeNull();
});

it('leaves a genuinely live call alone', function () {
    $live = liveCall(['started_at' => now()->subMinute()]);

    artisan('calls:close-stale')->assertSuccessful();

    expect($live->fresh()->status)->toBe(CallStatus::InProgress)
        ->and($live->fresh()->ended_at)->toBeNull();
});
