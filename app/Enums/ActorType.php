<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Who caused an event. The core of the "human judgment is never silently
 * overridden by AI" principle: every row in the system is attributable to a
 * human, the AI, or the system itself — and rendered distinctly in the UI.
 */
enum ActorType: string implements HasColor, HasLabel
{
    case User = 'user';
    case AiAgent = 'ai_agent';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::User => 'Human',
            self::AiAgent => 'AI Agent',
            self::System => 'System',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::User => 'gray',
            self::AiAgent => 'warning',
            self::System => 'info',
        };
    }
}
