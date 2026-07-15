<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use App\Enums\AnalysisPriority;
use App\Enums\RecordSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Machine-generated company analysis — regenerable, one row per generation. Never
 * touches the manual analysis. Findings (technical/marketing/recommendations)
 * each carry a confidence; the record carries full provenance so any finding
 * traces to the model + KB state that produced it.
 *
 * @property array|null $technical
 * @property array|null $marketing
 * @property array|null $recommendations
 * @property AnalysisPriority|null $inferred_priority
 */
class CompanyAiAnalysis extends Model
{
    use HasFactory, LogsEvents, SoftDeletes;

    protected $table = 'company_ai_analyses';

    const DELETED_AT = 'archived_at';

    protected $fillable = [
        'company_id',
        'technical',
        'marketing',
        'recommendations',
        'inferred_priority',
        'overall_confidence',
        'source_context_version',
        'model_id',
        'ai_run_id',
        'generated_at',
        'source',
        'created_by',
    ];

    protected $attributes = [
        'source' => 'ai',
    ];

    /**
     * Large finding JSON is kept out of the append-only audit log (the log
     * records that it changed, not the whole blob).
     *
     * @var array<int, string>
     */
    protected array $auditRedacted = ['technical', 'marketing', 'recommendations'];

    protected function casts(): array
    {
        return [
            'technical' => 'array',
            'marketing' => 'array',
            'recommendations' => 'array',
            'inferred_priority' => AnalysisPriority::class,
            'overall_confidence' => 'decimal:3',
            'generated_at' => 'datetime',
            'source' => RecordSource::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
