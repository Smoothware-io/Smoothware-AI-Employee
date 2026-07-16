<?php

namespace App\Services\Import;

use App\Enums\CompanyStatus;
use App\Enums\ImportRowDisposition;
use App\Enums\ImportStatus;
use App\Enums\Language;
use App\Enums\PreferredChannel;
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
 * industry), and queues a Phase-4 analysis for each NEW company.
 *
 * Note: a `preferred_channel` hint lands on the CONTACT, so a row carrying one but
 * no contact name has nowhere to put it and the hint is dropped — no person, no
 * personal preference. Imported records
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
            // Only Create/Match are ever written. Suppressed/Skip/Invalid rows are
            // not "not yet" — they are decided, and committing must not revisit
            // that decision.
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
            $attributes = [
                'name' => $mapped['name'],
                'domain' => $mapped['domain'] ?? null,
                'email' => $mapped['email'] ?? null,
                'phone' => $mapped['phone'] ?? null,
                'industry' => ($mapped['industry'] ?? null) ?: $import->default_industry,
                'city' => $mapped['city'] ?? null,
                // Unrecognised language text -> null, never a guess. The AI falls
                // back to the country, and then to asking.
                'language' => Language::fromImport($mapped['language'] ?? null),
                'status' => $import->default_status ?: CompanyStatus::Lead->value,
                'owner_id' => $import->default_owner_id,
                'campaign_id' => $import->campaign_id,
                'source' => RecordSource::Import,
                'created_by' => $import->created_by,
            ];

            // `companies.country` is NOT NULL with a default. Passing null
            // explicitly overrides the default and violates the constraint, so the
            // key is only set when we actually have a country. Unrecognised text
            // is dropped rather than truncated — the column is 2 chars, and half a
            // country code is worse than none.
            if ($country = self::countryCode($mapped['country'] ?? null)) {
                $attributes['country'] = $country;
            }

            $company = Company::create($attributes);

            // Queue the Phase-4 analysis for freshly-imported companies.
            GenerateCompanyAnalysis::dispatch($company->id);
        }

        if ($company === null) {
            return;
        }

        if (! empty($mapped['contact_first_name']) || ! empty($mapped['contact_last_name'])) {
            Contact::create([
                'company_id' => $company->id,
                'first_name' => ($mapped['contact_first_name'] ?? null) ?: 'Unknown',
                'last_name' => $mapped['contact_last_name'] ?? null,
                'job_title' => $mapped['contact_job_title'] ?? null,
                'email' => $mapped['contact_email'] ?? null,
                // Unrecognised free text normalises to null rather than a guess —
                // inventing a preference the person never stated is worse than
                // having none. See PreferredChannel::fromImport().
                'preferred_channel' => PreferredChannel::fromImport($mapped['preferred_channel'] ?? null),
                'source' => RecordSource::Import,
                'created_by' => $import->created_by,
            ]);
        }

        $row->forceFill(['company_id' => $company->id])->saveQuietly();
    }

    /**
     * An imported country -> ISO-3166 alpha-2, or null.
     *
     * Not cosmetic: the country decides which telemarketing regime applies to a
     * call, so a wrong one is a legal error, not a display bug. Anything we do not
     * recognise returns null so the column keeps its default rather than storing
     * a guess.
     */
    public static function countryCode(?string $value): ?string
    {
        $normalised = mb_strtolower(trim((string) $value));

        if ($normalised === '') {
            return null;
        }

        return match ($normalised) {
            'nl', 'nld', 'netherlands', 'nederland', 'the netherlands', 'holland' => 'NL',
            'be', 'bel', 'belgium', 'belgie', 'belgië', 'belgique' => 'BE',
            'de', 'deu', 'germany', 'duitsland', 'deutschland' => 'DE',
            'fr', 'fra', 'france', 'frankrijk' => 'FR',
            'gb', 'uk', 'gbr', 'united kingdom', 'england' => 'GB',
            'us', 'usa', 'united states' => 'US',
            'lu', 'lux', 'luxembourg' => 'LU',
            'at', 'aut', 'austria', 'oostenrijk' => 'AT',
            'es', 'esp', 'spain', 'spanje' => 'ES',
            'it', 'ita', 'italy', 'italie', 'italië' => 'IT',
            default => mb_strlen($normalised) === 2 ? mb_strtoupper($normalised) : null,
        };
    }
}
