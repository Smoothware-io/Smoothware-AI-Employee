<?php

namespace App\Support\Analysis;

/**
 * Objective technical signals gathered for a company's website (Phase 4). These
 * are factual (from a scan / PageSpeed), distinct from the AI's reasoning — the
 * technical section of the AI analysis is built straight from these.
 */
class WebsiteSignals
{
    public function __construct(
        public readonly ?string $domain,
        public readonly bool $reachable,
        public readonly int $pagespeed,     // 0-100
        public readonly int $mobileScore,   // 0-100
        public readonly int $seoScore,      // 0-100
        public readonly bool $ssl,
        public readonly ?string $cms,
        public readonly bool $analytics,
        public readonly bool $tracking,
    ) {}

    /**
     * The "Technical" findings group.
     *
     * @return array<int, array{key: string, label: string, assessment: string, confidence: float}>
     */
    public function toFindings(): array
    {
        return [
            ['key' => 'pagespeed', 'label' => 'PageSpeed', 'assessment' => "Score {$this->pagespeed}/100", 'confidence' => 0.9],
            ['key' => 'mobile', 'label' => 'Mobile', 'assessment' => "Score {$this->mobileScore}/100", 'confidence' => 0.85],
            ['key' => 'seo', 'label' => 'SEO (on-page)', 'assessment' => "Score {$this->seoScore}/100", 'confidence' => 0.7],
            ['key' => 'ssl', 'label' => 'SSL', 'assessment' => $this->ssl ? 'Valid HTTPS' : 'No / invalid SSL', 'confidence' => 0.95],
            ['key' => 'cms', 'label' => 'CMS', 'assessment' => $this->cms ?? 'Not detected', 'confidence' => $this->cms ? 0.8 : 0.4],
            ['key' => 'analytics', 'label' => 'Analytics', 'assessment' => $this->analytics ? 'Detected' : 'Not detected', 'confidence' => 0.7],
            ['key' => 'tracking', 'label' => 'Tracking', 'assessment' => $this->tracking ? 'Detected' : 'Not detected', 'confidence' => 0.6],
        ];
    }
}
