<?php

namespace App\Exceptions;

use App\Enums\TaskStatus;
use App\Models\Task;
use DomainException;

/**
 * Thrown when a task is asked to make a status transition the state machine
 * does not permit (see {@see TaskStatus}).
 */
class InvalidTaskTransition extends DomainException
{
    public static function between(Task $task, TaskStatus $from, TaskStatus $to): self
    {
        return new self("Task #{$task->getKey()} cannot move from '{$from->value}' to '{$to->value}'.");
    }
}
