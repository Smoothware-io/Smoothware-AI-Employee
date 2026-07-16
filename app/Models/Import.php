<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\LawfulBasis;
use App\Services\Import\CsvImporter;
use App\Services\Import\ImportCommitter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CSV import run. Managed via {@see CsvImporter} (stage)
 * and {@see ImportCommitter} (commit).
 *
 * Provenance (`list_source`, `lawful_basis`, `lawful_basis_notes`) answers
 * "where did this list come from and under what basis?" per batch — see
 * GO-LIVE-LEGAL.md item #2. A company traces back via
 * `import_rows.company_id` → `imports`.
 *
 * @property ImportStatus $status
 * @property LawfulBasis|null $lawful_basis
 * @property array|null $column_mapping
 */
class Import extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_name',
        'list_source',
        'lawful_basis',
        'lawful_basis_notes',
        'disk',
        'path',
        'status',
        'column_mapping',
        'default_owner_id',
        'default_status',
        'default_industry',
        'campaign_id',
        'create_count',
        'match_count',
        'skip_count',
        'invalid_count',
        'error',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'pending',
        'disk' => 'local',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'lawful_basis' => LawfulBasis::class,
            'column_mapping' => 'array',
        ];
    }

    /**
     * True when this batch's basis carries an assessment burden (legitimate
     * interest / other) but nobody recorded the reasoning.
     */
    public function hasUnjustifiedBasis(): bool
    {
        return $this->lawful_basis?->requiresAssessment()
            && blank($this->lawful_basis_notes);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
