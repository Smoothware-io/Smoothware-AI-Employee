<?php

namespace App\Services\Analysis;

use App\Contracts\WebsiteAnalyzer;
use App\Support\Analysis\WebsiteSignals;

/**
 * Deterministic, offline website scanner for dev/tests/CI — derives stable
 * signals from the domain string, no network. Swapped for HttpWebsiteAnalyzer in
 * production.
 */
class FakeWebsiteAnalyzer implements WebsiteAnalyzer
{
    public function analyze(?string $domain): WebsiteSignals
    {
        if ($domain === null || $domain === '') {
            return new WebsiteSignals(null, false, 0, 0, 0, false, null, false, false);
        }

        $seed = crc32($domain);

        return new WebsiteSignals(
            domain: $domain,
            reachable: true,
            pagespeed: 40 + ($seed % 56),           // 40–95
            mobileScore: 35 + (($seed >> 3) % 60),  // 35–94
            seoScore: 30 + (($seed >> 6) % 65),     // 30–94
            ssl: ($seed % 5) !== 0,
            cms: ['WordPress', 'Shopify', 'Wix', null][$seed % 4],
            analytics: ($seed % 3) !== 0,
            tracking: ($seed % 4) !== 0,
        );
    }
}
