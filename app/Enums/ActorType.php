<?php

namespace App\Enums;

/**
 * Who caused an event. The core of the "human judgment is never silently
 * overridden by AI" principle: every row in the system is attributable to a
 * human, the AI, or the system itself.
 */
enum ActorType: string
{
    case User = 'user';
    case AiAgent = 'ai_agent';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Human',
            self::AiAgent => 'AI Agent',
            self::System => 'System',
        };
    }
}
