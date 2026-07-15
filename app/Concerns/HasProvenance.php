<?php

namespace App\Concerns;

use App\Enums\RecordSource;
use App\Models\AiAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt-in on user-data models to record where each row came from. Requires
 * `source` (string, default 'manual') and nullable `ai_action_id` columns.
 *
 * This is the structural half of "human vs. AI data is distinct" (principle #2)
 * and "every AI record traces to an AI action" (principle #3): the UI renders an
 * AI badge from {@see isAiGenerated()}, and `ai_action_id` links straight to the
 * approval record that produced it.
 *
 * @property RecordSource $source
 * @property int|null $ai_action_id
 */
trait HasProvenance
{
    public function initializeHasProvenance(): void
    {
        $this->mergeCasts(['source' => RecordSource::class]);
    }

    /** The AI action that created this record, if any. */
    public function aiAction(): BelongsTo
    {
        return $this->belongsTo(AiAction::class);
    }

    public function isAiGenerated(): bool
    {
        return $this->source === RecordSource::Ai || $this->ai_action_id !== null;
    }
}
