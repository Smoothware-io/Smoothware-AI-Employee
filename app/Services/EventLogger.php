<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The single entry point for writing to the universal event log. Everything —
 * model traits, services, jobs, the AI layer — logs through here so actor
 * resolution and shape stay consistent.
 *
 * Actor resolution when not given explicitly:
 *   1. an authenticated user  -> ActorType::User
 *   2. otherwise (jobs, CLI)  -> ActorType::System
 * The AI layer passes ActorType::AiAgent explicitly.
 */
class EventLogger
{
    public function log(
        string $action,
        ?Model $entity = null,
        array $payload = [],
        ?ActorType $actorType = null,
        ?int $actorId = null,
    ): Event {
        return $this->write(
            action: $action,
            entityType: $entity?->getMorphClass() ?? 'system',
            entityId: $entity?->getKey(),
            payload: $payload,
            actorType: $actorType,
            actorId: $actorId,
        );
    }

    /**
     * Log against an entity by type/id when no model instance is handy (e.g. an
     * event about a record that was just hard-deleted).
     */
    public function logRaw(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $payload = [],
        ?ActorType $actorType = null,
        ?int $actorId = null,
    ): Event {
        return $this->write($action, $entityType, $entityId, $payload, $actorType, $actorId);
    }

    private function write(
        string $action,
        string $entityType,
        ?int $entityId,
        array $payload,
        ?ActorType $actorType,
        ?int $actorId,
    ): Event {
        [$resolvedType, $resolvedId] = $this->resolveActor($actorType, $actorId);

        return Event::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_type' => $resolvedType,
            'actor_id' => $resolvedId,
            'action' => $action,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: ActorType, 1: int|null}
     */
    private function resolveActor(?ActorType $actorType, ?int $actorId): array
    {
        if ($actorType !== null) {
            return [$actorType, $actorId];
        }

        if (Auth::check()) {
            return [ActorType::User, Auth::id()];
        }

        return [ActorType::System, null];
    }
}
