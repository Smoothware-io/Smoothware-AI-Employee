<?php

namespace App\Contracts;

use App\Support\Analysis\WebsiteSignals;

/**
 * Gathers objective technical signals for a company's website (PageSpeed, SSL,
 * CMS, analytics…). Behind an interface with an offline fake so Phase 4 builds
 * and tests without hitting real sites or the PageSpeed API.
 */
interface WebsiteAnalyzer
{
    public function analyze(?string $domain): WebsiteSignals;
}
