<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A derived, embedded chunk of a knowledge entry. Regenerated wholesale whenever
 * the entry changes, so it is not audited or soft-deleted.
 *
 * @property array<int, float>|null $embedding
 */
class KnowledgeChunk extends Model
{
    protected $fillable = [
        'knowledge_entry_id',
        'chunk_index',
        'content',
        'token_count',
        'embedding',
        'embedding_model',
        'embedding_dims',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'knowledge_entry_id');
    }
}
