<?php

use App\Enums\CallDirection;
use App\Models\Call;
use App\Models\Event;
use App\Models\User;
use App\Services\CallContentEraser;
use Illuminate\Support\Facades\Storage;

it('erases personal content but keeps call metadata', function () {
    Storage::fake('local');
    Storage::disk('local')->put('recordings/x.mp3', 'bytes');

    $call = Call::factory()->withContent()->create([
        'direction' => CallDirection::Inbound,
        'duration_seconds' => 300,
        'recording_disk' => 'local',
        'recording_path' => 'recordings/x.mp3',
    ]);
    $officer = User::factory()->create();

    app(CallContentEraser::class)->erase($call, $officer, 'gdpr_request');

    $call->refresh();

    // Content destroyed...
    expect($call->transcript)->toBeNull()
        ->and($call->summary)->toBeNull()
        ->and($call->from_number)->toBeNull()
        ->and($call->to_number)->toBeNull()
        ->and($call->recording_path)->toBeNull()
        ->and($call->content_erased_at)->not->toBeNull()
        ->and($call->erased_by)->toBe($officer->id)
        // ...metadata retained for reporting.
        ->and($call->duration_seconds)->toBe(300)
        ->and($call->direction)->toBe(CallDirection::Inbound);

    // Recording object removed from storage.
    Storage::disk('local')->assertMissing('recordings/x.mp3');

    // Erasure is audited.
    expect(Event::where('entity_id', $call->id)->where('action', 'call.content_erased')->exists())->toBeTrue();
});

it('is idempotent — erasing an already-erased call does nothing', function () {
    Storage::fake('local');
    $call = Call::factory()->withContent()->create(['recording_disk' => 'local']);
    $eraser = app(CallContentEraser::class);

    $eraser->erase($call);
    $count = Event::where('action', 'call.content_erased')->count();

    $eraser->erase($call->fresh());

    expect(Event::where('action', 'call.content_erased')->count())->toBe($count);
});
