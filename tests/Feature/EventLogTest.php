<?php

use App\Enums\ActorType;
use App\Models\Event;
use App\Models\User;
use App\Services\EventLogger;

use function Pest\Laravel\actingAs;

it('logs a system actor when no user is authenticated', function () {
    $event = app(EventLogger::class)->log('thing.happened', payload: ['k' => 'v']);

    expect($event->actor_type)->toBe(ActorType::System)
        ->and($event->actor_id)->toBeNull()
        ->and($event->action)->toBe('thing.happened')
        ->and($event->payload)->toBe(['k' => 'v']);
});

it('attributes events to the authenticated user by default', function () {
    $user = User::factory()->create();
    actingAs($user);

    $event = app(EventLogger::class)->log('thing.happened');

    expect($event->actor_type)->toBe(ActorType::User)
        ->and($event->actor_id)->toBe($user->id);
});

it('records an explicit AI-agent actor when asked', function () {
    $event = app(EventLogger::class)->log(
        action: 'ai.said',
        actorType: ActorType::AiAgent,
    );

    expect($event->actor_type)->toBe(ActorType::AiAgent);
});

it('is append-only: events cannot be updated', function () {
    $event = app(EventLogger::class)->log('thing.happened');

    expect(fn () => $event->update(['action' => 'tampered']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('is append-only: events cannot be deleted', function () {
    $event = app(EventLogger::class)->log('thing.happened');

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('auto-logs create, update and archive via the LogsEvents trait', function () {
    $user = User::factory()->create();
    $user->update(['is_active' => false]);
    $user->delete(); // soft delete = archive

    expect(actionsFor($user))->toContain('user.created', 'user.updated', 'user.archived');
});

it('redacts PII values in the created event but keeps the field names', function () {
    $user = User::factory()->create();

    $attrs = eventFor($user, 'user.created')->payload['attributes'];

    // The fields are still recorded (audit stays complete)...
    expect($attrs)->toHaveKeys(['name', 'email', 'is_active'])
        // ...but personal-data VALUES are never persisted to the immutable log.
        ->and($attrs['name'])->toBe(User::REDACTED)
        ->and($attrs['email'])->toBe(User::REDACTED)
        // Non-PII context is preserved.
        ->and($attrs['is_active'])->toBeTrue()
        // Hidden secrets are excluded entirely.
        ->and($attrs)->not->toHaveKey('password');
});

it('redacts PII in update diffs but preserves non-PII before/after values', function () {
    $user = User::factory()->create(['is_active' => true]);

    $user->update(['name' => 'Renamed', 'is_active' => false]);

    $payload = eventFor($user, 'user.updated')->payload;

    expect($payload['before']['name'])->toBe(User::REDACTED)
        ->and($payload['after']['name'])->toBe(User::REDACTED)
        ->and((bool) $payload['before']['is_active'])->toBeTrue()
        ->and((bool) $payload['after']['is_active'])->toBeFalse();
});

/**
 * The latest event with the given action recorded against a user.
 */
function eventFor(User $user, string $action): Event
{
    return Event::query()
        ->where('entity_type', $user->getMorphClass())
        ->where('entity_id', $user->id)
        ->where('action', $action)
        ->latest('id')
        ->firstOrFail();
}

/**
 * Distinct event actions recorded against a given user.
 *
 * @return array<int, string>
 */
function actionsFor(User $user): array
{
    return Event::query()
        ->where('entity_type', $user->getMorphClass())
        ->where('entity_id', $user->id)
        ->pluck('action')
        ->all();
}
