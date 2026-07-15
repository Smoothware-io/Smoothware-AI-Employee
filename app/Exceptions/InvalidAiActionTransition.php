<?php

namespace App\Exceptions;

use App\Enums\AiActionStatus;
use App\Models\AiAction;
use DomainException;

/**
 * Thrown when code attempts an illegal transition on an AI action (e.g.
 * approving something already rejected, or applying a draft that was never
 * approved). Guards the human-in-the-loop guarantee.
 */
class InvalidAiActionTransition extends DomainException
{
    public static function for(AiAction $action, string $attempted): self
    {
        $current = $action->status instanceof AiActionStatus
            ? $action->status->value
            : (string) $action->status;

        return new self(
            "Cannot {$attempted} AI action #{$action->getKey()}: it is currently '{$current}'."
        );
    }
}
