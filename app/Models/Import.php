<?php

namespace App\Models;

use App\Enums\ImportStatus;
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
 * @property ImportStatus $status
 * @property array|null $column_mapping
 */
class Import extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_name',
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
            'column_mapping' => 'array',
        ];
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
