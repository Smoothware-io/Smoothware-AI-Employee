<?php

namespace App\Contracts;

use App\Models\Company;
use App\Support\Analysis\AnalysisResult;
use App\Support\Analysis\WebsiteSignals;

/**
 * The LLM that turns website signals + our knowledge base into a marketing
 * assessment and recommendations (Claude in prod). Given the retrieved KB chunks
 * (our services) so recommendations stay grounded in what Smoothware offers.
 */
interface CompanyAnalysisLlm
{
    /**
     * @param  array<int, array{id: int, content: string}>  $chunks
     * @param  array<int, string>  $rules
     */
    public function analyze(Company $company, WebsiteSignals $signals, array $chunks, array $rules): AnalysisResult;
}
