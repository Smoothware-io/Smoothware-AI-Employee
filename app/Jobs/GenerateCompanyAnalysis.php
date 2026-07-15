<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Analysis\CompanyAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates (or regenerates) a company's AI analysis off the request cycle —
 * dispatched from the "Generate analysis" UI action.
 */
class GenerateCompanyAnalysis implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(CompanyAnalyzer $analyzer): void
    {
        $company = Company::find($this->companyId);

        if ($company) {
            $analyzer->analyze($company);
        }
    }
}
