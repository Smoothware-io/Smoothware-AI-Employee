<?php

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Exceptions\InvalidAiActionTransition;
use App\Models\AiAction;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The reusable "AI proposes -> human approves/rejects -> action executes"
 * engine (Phase 0). Phases 3, 4 and 6 all drive their AI side effects through
 * this one service instead of each building an approval flow. Every transition
 * is validated and written to the event log.
 *
 * @phpstan-type ActionMeta array{
 *     confidence_score?: float|string|null,
 *     source_context_version?: string|null,
 *     model_id?: string|null,
 *     ai_run_id?: string|null,
 *     requested_by?: int|null,
 * }
 */
class AiActionService
{
    public function __construct(private EventLogger $events) {}

    /**
     * The AI proposes an action. It ships as a draft awaiting human review —
     * the Phase 0 default ("start with human-in-the-loop, earn autonomy").
     *
     * @param  ActionMeta  $meta
     */
    public function propose(AiActionType|string $type, array $payload, array $meta = []): AiAction
    {
        $action = new AiAction([
            'action_type' => $type instanceof AiActionType ? $type->value : $type,
            'proposed_payload' => $payload,
            'confidence_score' => $meta['confidence_score'] ?? null,
            'source_context_version' => $meta['source_context_version'] ?? null,
            'model_id' => $meta['model_id'] ?? null,
            'ai_run_id' => $meta['ai_run_id'] ?? null,
            'requested_by' => $meta['requested_by'] ?? null,
        ]);
        $action->status = AiActionStatus::Draft;
        $action->save();

        $this->events->log(
            action: 'ai_action.proposed',
            entity: $action,
            payload: [
                'action_type' => $action->action_type,
                'confidence_score' => $action->confidence_score,
            ],
            actorType: ActorType::AiAgent,
        );

        return $action;
    }

    /**
     * A human approves a draft. This records the decision but does NOT execute
     * it — call {@see apply()} (or use {@see approveAndApply()}).
     */
    public function approve(AiAction $action, User $reviewer, ?string $notes = null): AiAction
    {
        if (! $action->isDraft()) {
            throw InvalidAiActionTransition::for($action, 'approve');
        }

        $action->forceFill([
            'status' => AiActionStatus::Approved,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        $this->events->log(
            action: 'ai_action.approved',
            entity: $action,
            payload: ['reviewer_id' => $reviewer->getKey()],
            actorType: ActorType::User,
            actorId: $reviewer->getKey(),
        );

        return $action;
    }

    /** A human rejects a draft with a reason. It will never execute. */
    public function reject(AiAction $action, User $reviewer, string $reason): AiAction
    {
        if (! $action->isDraft()) {
            throw InvalidAiActionTransition::for($action, 'reject');
        }

        $action->forceFill([
            'status' => AiActionStatus::Rejected,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ])->save();

        $this->events->log(
            action: 'ai_action.rejected',
            entity: $action,
            payload: ['reviewer_id' => $reviewer->getKey(), 'reason' => $reason],
            actorType: ActorType::User,
            actorId: $reviewer->getKey(),
        );

        return $action;
    }

    /**
     * Execute an approved (or auto-applied) action. The $executor performs the
     * real side effect — creating the Company / Note / Task / etc. — and returns
     * the affected model, which is recorded as this action's target. The side
     * effect and the applied state commit together in one transaction.
     *
     * @param  Closure(AiAction): ?Model  $executor
     */
    public function apply(AiAction $action, Closure $executor): AiAction
    {
        if (! in_array($action->status, [AiActionStatus::Approved, AiActionStatus::AutoApplied], true)) {
            throw InvalidAiActionTransition::for($action, 'apply');
        }

        if ($action->isApplied()) {
            throw InvalidAiActionTransition::for($action, 'apply (already applied)');
        }

        return DB::transaction(function () use ($action, $executor) {
            $target = $executor($action);

            $action->forceFill([
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target?->getKey(),
                'applied_at' => now(),
            ])->save();

            $this->events->log(
                action: 'ai_action.applied',
                entity: $action,
                payload: [
                    'target_type' => $action->target_type,
                    'target_id' => $action->target_id,
                ],
            );

            return $action;
        });
    }

    /** Convenience: approve then immediately execute. */
    public function approveAndApply(AiAction $action, User $reviewer, Closure $executor, ?string $notes = null): AiAction
    {
        $this->approve($action, $reviewer, $notes);

        return $this->apply($action, $executor);
    }

    /**
     * Earned-autonomy path: the AI proposes and applies without human review.
     * Still fully audited (status = auto_applied, no reviewer). The decision to
     * use this — per action type / confidence threshold — belongs to the
     * calling phase, not here.
     *
     * @param  Closure(AiAction): ?Model  $executor
     * @param  ActionMeta  $meta
     */
    public function autoApply(AiActionType|string $type, array $payload, Closure $executor, array $meta = []): AiAction
    {
        $action = $this->propose($type, $payload, $meta);
        $action->forceFill(['status' => AiActionStatus::AutoApplied])->save();

        return $this->apply($action, $executor);
    }
}
