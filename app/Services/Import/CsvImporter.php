<?php

namespace App\Services\Import;

use App\Enums\ImportRowDisposition;
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Receptionist\CompanyMatcher;
use App\Services\SuppressionList;
use Illuminate\Support\Facades\Storage;

/**
 * Stages a CSV import (Phase 5): parse → auto-map (or use the stored mapping) →
 * validate → dedup against existing companies (the SAME CompanyMatcher as the
 * Phase 3 receptionist) → write import_rows with a disposition each, and roll up
 * the create/match/skip/invalid counts. This is the data behind the preview —
 * nothing is created here.
 */
class CsvImporter
{
    public function __construct(
        private CompanyMatcher $matcher,
        private SuppressionList $suppressions,
    ) {}

    public function stage(Import $import): void
    {
        [$headers, $records] = $this->readCsv(Storage::disk($import->disk)->path($import->path));

        $mapping = $import->column_mapping ?: $this->autoMap($headers);

        $import->rows()->delete();
        $counts = ['create' => 0, 'match' => 0, 'skip' => 0, 'invalid' => 0, 'suppressed' => 0];

        foreach ($records as $index => $record) {
            $assoc = array_combine($headers, $record);
            $mapped = $this->applyMapping($assoc, $mapping);
            [$disposition, $companyId, $errors] = $this->classify($mapped);

            $import->rows()->create([
                'row_number' => $index + 1,
                'raw' => $assoc,
                'mapped' => $mapped,
                'disposition' => $disposition,
                'company_id' => $companyId,
                'errors' => $errors ?: null,
            ]);

            $counts[$disposition->value]++;
        }

        $import->forceFill([
            'column_mapping' => $mapping,
            'create_count' => $counts['create'],
            'match_count' => $counts['match'],
            'skip_count' => $counts['skip'],
            'invalid_count' => $counts['invalid'],
            'suppressed_count' => $counts['suppressed'],
            'status' => ImportStatus::Previewed,
        ])->save();
    }

    /**
     * @param  array<string, string|null>  $mapped
     * @return array{0: ImportRowDisposition, 1: int|null, 2: array<string, string>}
     */
    private function classify(array $mapped): array
    {
        $name = trim((string) ($mapped['name'] ?? ''));
        $email = $mapped['email'] ?? null;

        if ($name === '' && array_filter($mapped, fn ($v) => trim((string) $v) !== '') === []) {
            return [ImportRowDisposition::Skip, null, []];
        }
        if ($name === '') {
            return [ImportRowDisposition::Invalid, null, ['name' => 'Company name is required']];
        }
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [ImportRowDisposition::Invalid, null, ['email' => "Invalid email: {$email}"]];
        }

        // Before anything else worth doing: has this person told us to stop?
        // Checked here rather than at commit so the rep SEES it in the preview
        // and cannot commit a batch that would contact an objector.
        $suppression = $this->suppressions->firstMatch(
            phone: $mapped['phone'] ?? null,
            email: $mapped['email'] ?? null,
            domain: $mapped['domain'] ?? null,
        );

        if ($suppression !== null) {
            return [ImportRowDisposition::Suppressed, null, [
                'suppressed' => 'On the do-not-contact list ('.$suppression->type->value.', '.$suppression->source->getLabel().')',
            ]];
        }

        $match = $this->matcher->match($name, $mapped['phone'] ?? null, $mapped['domain'] ?? null);
        if ($match !== null && $match['confidence'] >= (float) config('receptionist.match.min_confidence', 0.6)) {
            return [ImportRowDisposition::Match, $match['company']->id, []];
        }

        return [ImportRowDisposition::Create, null, []];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, string> field => header
     */
    private function autoMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            $normalised = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));

            $field = match (true) {
                in_array($normalised, ['name', 'company', 'companyname', 'organisation', 'organization'], true) => 'name',
                in_array($normalised, ['domain', 'website', 'url', 'web'], true) => 'domain',
                in_array($normalised, ['email', 'emailaddress', 'companyemail'], true) => 'email',
                in_array($normalised, ['phone', 'telephone', 'tel', 'phonenumber', 'mobile'], true) => 'phone',
                in_array($normalised, ['industry', 'sector', 'branche'], true) => 'industry',
                // Previously DROPPED even when the sheet had them. City and
                // country are not decoration: country decides which telemarketing
                // regime applies, and language decides whether the call is even
                // comprehensible.
                in_array($normalised, ['city', 'plaats', 'stad', 'location', 'locatie'], true) => 'city',
                in_array($normalised, ['country', 'land', 'countrycode'], true) => 'country',
                in_array($normalised, ['language', 'taal', 'lang'], true) => 'language',
                in_array($normalised, ['jobtitle', 'title', 'functie', 'role', 'rol'], true) => 'contact_job_title',
                // Per-lead targeting, written by a human before the call. These
                // populate the MANUAL analysis (Phase 4) — the rep's judgment,
                // which the AI may read but must never overwrite.
                in_array($normalised, ['painpoints', 'pain', 'problem', 'problemen', 'issues'], true) => 'pain_points',
                in_array($normalised, ['opportunities', 'opportunity', 'kansen', 'target', 'angle', 'hook'], true) => 'opportunities',
                in_array($normalised, ['notes', 'note', 'notities', 'opmerkingen', 'remarks'], true) => 'notes',
                in_array($normalised, ['priority', 'prioriteit'], true) => 'priority',
                in_array($normalised, ['firstname', 'contactfirstname', 'contact'], true) => 'contact_first_name',
                in_array($normalised, ['lastname', 'surname', 'contactlastname'], true) => 'contact_last_name',
                in_array($normalised, ['contactemail'], true) => 'contact_email',
                in_array($normalised, ['preferredchannel', 'preferredcontactmethod', 'contactpreference', 'channel', 'preference'], true) => 'preferred_channel',
                default => null,
            };

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $header;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string|null>  $assoc
     * @param  array<string, string>  $mapping
     * @return array<string, string|null>
     */
    private function applyMapping(array $assoc, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $field => $header) {
            $out[$field] = array_key_exists($header, $assoc) ? trim((string) $assoc[$header]) : null;
        }

        return $out;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|null>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = array_map(fn ($h): string => trim((string) $h), fgetcsv($handle, escape: '') ?: []);
        $count = count($headers);

        $records = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $records[] = array_pad(array_slice($row, 0, $count), $count, null);
        }
        fclose($handle);

        return [$headers, $records];
    }
}
