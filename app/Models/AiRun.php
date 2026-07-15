<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A single AI invocation and its ops metrics. Created by the orchestration layer
 * (Phase 3+); read by the Phase 8 AI-ops dashboard.
 *
 * @property bool $grounded
 * @property bool $fallback_to_human
 */
class AiRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'kind',
        'model_id',
        'context_version',
        'subject_type',
        'subject_id',
        'grounded',
        'fallback_to_human',
        'retrieved_chunk_ids',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'cost',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'grounded' => 'boolean',
            'fallback_to_human' => 'boolean',
            'retrieved_chunk_ids' => 'array',
            'meta' => 'array',
            'cost' => 'decimal:5',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }
}
