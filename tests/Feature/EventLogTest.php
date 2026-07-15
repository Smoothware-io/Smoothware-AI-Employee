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
    $user = User::factory()->create(['name' => 'Original']);

    expect(actionsFor($user))->toContain('user.created');

    $user->update(['name' => 'Renamed']);

    $updated = Event::where('action', 'user.updated')->latest('id')->first();
    expect($updated)->not->toBeNull()
        ->and($updated->payload['before']['name'])->toBe('Original')
        ->and($updated->payload['after']['name'])->toBe('Renamed');

    // Soft delete = "archived" in Smoothware's vocabulary.
    $user->delete();
    expect(actionsFor($user))->toContain('user.archived');
});

/**
 * Distinct event actions recorded against a given entity.
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
