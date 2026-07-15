<?php

use App\Jobs\PurgeExpiredCallContent;
use App\Models\Call;
use App\Services\CallContentEraser;
use Illuminate\Support\Facades\Storage;

it('purges call content past its retention window but keeps in-window calls', function () {
    Storage::fake('local');

    $expired = Call::factory()->withContent()->create([
        'recording_disk' => 'local',
        'retention_expires_at' => now()->subDay(),
    ]);
    $inWindow = Call::factory()->withContent()->create([
        'recording_disk' => 'local',
        'retention_expires_at' => now()->addDays(30),
    ]);

    (new PurgeExpiredCallContent)->handle(app(CallContentEraser::class));

    expect($expired->fresh()->content_erased_at)->not->toBeNull()
        ->and($expired->fresh()->transcript)->toBeNull()
        ->and($inWindow->fresh()->content_erased_at)->toBeNull()
        ->and($inWindow->fresh()->transcript)->not->toBeNull();
});
