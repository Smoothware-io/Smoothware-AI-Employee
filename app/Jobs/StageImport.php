<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Import\CsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Parses + dedups an uploaded CSV into staged rows for the preview. */
class StageImport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $importId) {}

    public function handle(CsvImporter $importer): void
    {
        $import = Import::find($this->importId);

        if (! $import) {
            return;
        }

        try {
            $importer->stage($import);
        } catch (\Throwable $e) {
            $import->forceFill(['status' => ImportStatus::Failed, 'error' => $e->getMessage()])->save();
        }
    }
}
