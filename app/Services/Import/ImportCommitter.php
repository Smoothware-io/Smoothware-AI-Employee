<?php

namespace App\Services\Import;

use App\Enums\CompanyStatus;
use App\Enums\ImportRowDisposition;
use App\Enums\ImportStatus;
use App\Enums\RecordSource;
use App\Jobs\GenerateCompanyAnalysis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Commits a previewed import (Phase 5): creates new companies / links matched
 * ones, adds contacts, applies the import defaults (owner/status/campaign/
 * industry), and queues a Phase-4 analysis for each NEW company. Imported records
 * are tagged source=Import. Idempotent — only runs on a `previewed` import.
 */
class ImportCommitter
{
    public function commit(Import $import): void
    {
        if ($import->status !== ImportStatus::Previewed) {
            return;
        }

        DB::transaction(function () use ($import) {
            $import->rows()
                ->whereIn('disposition', [ImportRowDisposition::Create->value, ImportRowDisposition::Match->value])
                ->cursor()
                ->each(fn (ImportRow $row) => $this->applyRow($import, $row));

            $import->forceFill(['status' => ImportStatus::Completed])->save();
        });
    }

    private function applyRow(Import $import, ImportRow $row): void
    {
        $mapped = $row->mapped;

        if ($row->disposition === ImportRowDisposition::Match && $row->company_id) {
            $company = Company::find($row->company_id);
        } else {
            $company = Company::create([
                'name' => $mapped['name'],
                'domain' => $mapped['domain'] ?? null,
                'email' => $mapped['email'] ?? null,
                'phone' => $mapped['phone'] ?? null,
                'industry' => $mapped['industry'] ?: $import->default_industry,
                'status' => $import->default_status ?: CompanyStatus::Lead->value,
                'owner_id' => $import->default_owner_id,
                'campaign_id' => $import->campaign_id,
                'source' => RecordSource::Import,
                'created_by' => $import->created_by,
            ]);

            // Queue the Phase-4 analysis for freshly-imported companies.
            GenerateCompanyAnalysis::dispatch($company->id);
        }

        if ($company === null) {
            return;
        }

        if (! empty($mapped['contact_first_name']) || ! empty($mapped['contact_last_name'])) {
            Contact::create([
                'company_id' => $company->id,
                'first_name' => $mapped['contact_first_name'] ?: 'Unknown',
                'last_name' => $mapped['contact_last_name'] ?? null,
                'email' => $mapped['contact_email'] ?? null,
                'source' => RecordSource::Import,
                'created_by' => $import->created_by,
            ]);
        }

        $row->forceFill(['company_id' => $company->id])->saveQuietly();
    }
}
