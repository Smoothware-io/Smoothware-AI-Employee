<?php

namespace App\Models;

use App\Concerns\HasProvenance;
use App\Concerns\LogsEvents;
use App\Enums\NoteCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rich-text note on a company. The body is free text that may contain
 * personal data, so it never lands in the append-only audit log.
 *
 * @property NoteCategory $category
 */
class Note extends Model
{
    use HasFactory, HasProvenance, LogsEvents, SoftDeletes;

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'category',
        'body',
        'source',
        'ai_action_id',
        'created_by',
    ];

    /**
     * @var array<int, string>
     */
    protected array $auditRedacted = ['body'];

    protected function casts(): array
    {
        return [
            'category' => NoteCategory::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The note's author. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
