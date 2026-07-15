<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\KnowledgeType;
use App\Enums\PublishStatus;
use App\Jobs\EmbedKnowledgeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One knowledge-base entry (any KnowledgeType). Only `published` entries are
 * retrieved for AI answers. Editor history is the entry's event timeline;
 * `last_verified_at` flags stale info.
 *
 * @property KnowledgeType $type
 * @property PublishStatus $status
 * @property array|null $data
 */
class KnowledgeEntry extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'type',
        'title',
        'body',
        'data',
        'status',
        'last_verified_at',
        'verified_by',
        'source',
        'ai_action_id',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Body and structured data are large free text — kept out of the append-only
     * audit log (which records that they changed, not their contents).
     *
     * @var array<int, string>
     */
    protected array $auditRedacted = ['body', 'data'];

    protected function casts(): array
    {
        return [
            'type' => KnowledgeType::class,
            'status' => PublishStatus::class,
            'data' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Re-embed whenever the content or publication status changes. The job
        // clears chunks for non-published entries, so unpublishing removes them
        // from retrieval. Runs on the queue (sync in tests).
        static::saved(function (self $entry): void {
            if ($entry->wasRecentlyCreated || $entry->wasChanged(['title', 'body', 'status', 'data'])) {
                EmbedKnowledgeEntry::dispatch($entry->getKey());
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === PublishStatus::Published;
    }

    /** Stale if never verified, or not verified within the given window. */
    public function isStale(int $days = 90): bool
    {
        return $this->last_verified_at === null
            || $this->last_verified_at->lt(now()->subDays($days));
    }

    /** The text that gets chunked + embedded (title gives FAQ questions weight). */
    public function embeddableText(): string
    {
        return trim($this->title."\n\n".strip_tags((string) $this->body));
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
