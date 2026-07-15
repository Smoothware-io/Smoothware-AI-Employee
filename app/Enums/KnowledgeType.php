<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The knowledge-base content types (the brief's KB sections). One flexible
 * `knowledge_entries` table stores them all; type-specific structure lives in
 * the entry's `data` JSON.
 */
enum KnowledgeType: string implements HasLabel
{
    case CompanyInfo = 'company_info';
    case Service = 'service';
    case Faq = 'faq';
    case Pricing = 'pricing';
    case Process = 'process';
    case Portfolio = 'portfolio';

    public function getLabel(): string
    {
        return match ($this) {
            self::CompanyInfo => 'Company info',
            self::Service => 'Service',
            self::Faq => 'FAQ',
            self::Pricing => 'Pricing guideline',
            self::Process => 'Process',
            self::Portfolio => 'Portfolio',
        };
    }
}
