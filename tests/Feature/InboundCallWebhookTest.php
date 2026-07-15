<?php

use App\Models\AiAction;
use App\Models\Call;

use function Pest\Laravel\postJson;

it('accepts an inbound webhook, records the call, and queues receptionist analysis', function () {
    $response = postJson('/webhooks/telephony/inbound', [
        'from' => '+31612345678',
        'to' => '+31201234567',
        'transcript' => 'Hi, I would like a new website for my restaurant.',
    ]);

    $response->assertStatus(202)->assertJsonPath('status', 'accepted');

    expect(Call::count())->toBe(1)
        ->and(Call::first()->external_provider)->toBe('fake')
        ->and(Call::first()->retention_expires_at)->not->toBeNull();

    // Queue is sync in tests, so the pipeline already ran and drafted an intake.
    expect(AiAction::where('action_type', 'receptionist_intake')->exists())->toBeTrue();
});

it('rejects a webhook with the wrong secret when one is configured', function () {
    config(['receptionist.webhook_secret' => 's3cret']);

    postJson('/webhooks/telephony/inbound', ['from' => '+31600000000'])
        ->assertStatus(401);

    expect(Call::count())->toBe(0);
});
