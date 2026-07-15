<?php

namespace App\Services\Analysis;

use App\Contracts\WebsiteAnalyzer;
use App\Support\Analysis\WebsiteSignals;
use Illuminate\Support\Facades\Http;

/**
 * Production website scanner: fetches the site and (optionally) Google PageSpeed
 * Insights. Best-effort — unreachable sites degrade gracefully. Wired when
 * WEBSITE_ANALYZER_DRIVER=http; not exercised in CI (fake is the default).
 *
 * ⚠️ Heuristic CMS/analytics detection — refine against real sites during Phase 4
 * activation. PageSpeed needs PAGESPEED_API_KEY (falls back to a heuristic).
 */
class HttpWebsiteAnalyzer implements WebsiteAnalyzer
{
    public function __construct(private ?string $pageSpeedKey = null) {}

    public function analyze(?string $domain): WebsiteSignals
    {
        if ($domain === null || $domain === '') {
            return new WebsiteSignals(null, false, 0, 0, 0, false, null, false, false);
        }

        $url = 'https://'.preg_replace('#^https?://#', '', $domain);

        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable) {
            return new WebsiteSignals($domain, false, 0, 0, 0, false, null, false, false);
        }

        $html = $response->body();
        $ssl = $response->successful();

        $cms = match (true) {
            str_contains($html, 'wp-content') => 'WordPress',
            str_contains($html, 'cdn.shopify') => 'Shopify',
            str_contains($html, 'wix.com') => 'Wix',
            default => null,
        };
        $analytics = str_contains($html, 'gtag(') || str_contains($html, 'google-analytics');
        $tracking = str_contains($html, 'fbq(') || str_contains($html, 'gtm.js');
        $pagespeed = $this->pageSpeed($url);

        return new WebsiteSignals(
            domain: $domain,
            reachable: true,
            pagespeed: $pagespeed,
            mobileScore: $pagespeed, // separate mobile PSI call can refine this
            seoScore: $this->seoHeuristic($html),
            ssl: $ssl,
            cms: $cms,
            analytics: $analytics,
            tracking: $tracking,
        );
    }

    private function pageSpeed(string $url): int
    {
        if (! $this->pageSpeedKey) {
            return 60; // neutral placeholder without an API key
        }

        try {
            $data = Http::timeout(30)->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                'url' => $url,
                'key' => $this->pageSpeedKey,
                'strategy' => 'mobile',
            ])->json();

            return (int) round(($data['lighthouseResult']['categories']['performance']['score'] ?? 0.6) * 100);
        } catch (\Throwable) {
            return 60;
        }
    }

    private function seoHeuristic(string $html): int
    {
        $score = 40;
        $score += str_contains($html, '<title') ? 20 : 0;
        $score += str_contains($html, 'name="description"') ? 20 : 0;
        $score += str_contains($html, 'og:') ? 10 : 0;
        $score += str_contains($html, 'application/ld+json') ? 10 : 0;

        return min(100, $score);
    }
}
