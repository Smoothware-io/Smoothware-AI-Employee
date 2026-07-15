<?php

namespace App\Services\Receptionist;

use App\Models\Company;
use Illuminate\Support\Str;

/**
 * Fuzzy company deduplication — matches a caller's company against existing
 * records by domain, phone, then name similarity. Shared by the Phase 3
 * receptionist and Phase 5 CSV import. Small-KB scale: it scans companies in
 * PHP; at larger scale, switch name matching to Postgres `pg_trgm`.
 */
class CompanyMatcher
{
    /**
     * @return array{company: Company, confidence: float}|null
     */
    public function match(?string $name, ?string $phone, ?string $domain): ?array
    {
        $nName = $name ? $this->normalizeName($name) : null;
        $nPhone = $phone ? $this->normalizePhone($phone) : null;
        $nDomain = $domain ? $this->normalizeDomain($domain) : null;

        if ($nName === null && ($nPhone === null || $nPhone === '') && $nDomain === null) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach (Company::query()->get() as $company) {
            $score = 0.0;

            if ($nDomain !== null && $company->domain && $this->normalizeDomain($company->domain) === $nDomain) {
                $score = max($score, 0.95);
            }

            if ($nPhone !== null && $nPhone !== '' && $company->phone && $this->normalizePhone($company->phone) === $nPhone) {
                $score = max($score, 0.90);
            }

            if ($nName !== null && $company->name) {
                similar_text($nName, $this->normalizeName($company->name), $percent);
                if ($percent >= 80.0) {
                    $score = max($score, min(0.85, $percent / 100));
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $company;
            }
        }

        return $best ? ['company' => $best, 'confidence' => round($bestScore, 3)] : null;
    }

    private function normalizeName(string $name): string
    {
        // Drop common legal suffixes + non-alphanumerics for a stable comparison.
        $name = Str::lower($name);
        $name = (string) preg_replace('/\b(bv|b\.v\.|nv|n\.v\.|ltd|inc|llc|gmbh)\b/', '', $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = Str::lower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = (string) preg_replace('/^www\./', '', $domain);

        return rtrim(explode('/', $domain)[0], '.');
    }
}
