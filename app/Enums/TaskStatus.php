<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The Task status state machine. Tasks are a real workflow (not a boolean
 * "completed" flag) because Phase 7 follow-up automation hangs off these
 * transitions. Transition rules live here; the Task model exposes the guarded
 * transition methods and throws InvalidTaskTransition on anything not permitted.
 *
 *   open --------- start ------> in_progress
 *   open/blocked/in_progress --- complete ---> completed
 *   open/blocked/in_progress --- cancel ------> cancelled
 *   in_progress -- block ------> blocked
 *   blocked ------ unblock ----> in_progress
 *   completed/cancelled -- reopen -> open
 */
enum TaskStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Target states reachable from this state.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Blocked, self::Completed, self::Cancelled],
            self::Blocked => [self::InProgress, self::Completed, self::Cancelled],
            self::Completed => [self::Open],
            self::Cancelled => [self::Open],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Completed and cancelled are "resting" states (reopenable, not active). */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::InProgress => 'info',
            self::Blocked => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
