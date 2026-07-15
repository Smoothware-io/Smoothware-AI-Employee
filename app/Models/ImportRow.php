<?php

namespace App\Models;

use App\Enums\ImportRowDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single staged CSV row with its dedup/validation disposition.
 *
 * @property ImportRowDisposition $disposition
 * @property array $raw
 * @property array $mapped
 */
class ImportRow extends Model
{
    protected $fillable = [
        'import_id',
        'row_number',
        'raw',
        'mapped',
        'disposition',
        'company_id',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'mapped' => 'array',
            'errors' => 'array',
            'disposition' => ImportRowDisposition::class,
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
