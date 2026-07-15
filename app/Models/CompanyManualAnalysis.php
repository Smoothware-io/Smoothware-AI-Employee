<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\AnalysisPriority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The rep's own company analysis — human-owned, one per company. AI code must
 * NEVER write here (product principle #2). Free-text business assessment is kept
 * out of the append-only audit log.
 *
 * @property AnalysisPriority|null $priority
 */
class CompanyManualAnalysis extends Model
{
    use HasFactory, LogsEvents, SoftDeletes;

    protected $table = 'company_manual_analyses';

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'pain_points',
        'opportunities',
        'notes',
        'priority',
        'created_by',
        'updated_by',
    ];

    /**
     * @var array<int, string>
     */
    protected array $auditRedacted = ['pain_points', 'opportunities', 'notes'];

    protected function casts(): array
    {
        return [
            'priority' => AnalysisPriority::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
