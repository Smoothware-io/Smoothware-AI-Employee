<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\Import\ImportCommitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Creates/links the companies + contacts for a previewed import. */
class CommitImport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $importId) {}

    public function handle(ImportCommitter $committer): void
    {
        $import = Import::find($this->importId);

        if ($import) {
            $committer->commit($import);
        }
    }
}
