<?php

namespace App\Services\Outbound;

use App\Models\Company;
use App\Services\ContextVersion;
use App\Services\KnowledgeRetriever;
use App\Services\PromptRuleSetService;

/**
 * Builds what the AI is told before it opens its mouth (Phase 6).
 *
 * This is where "the AI knows about Smoothware" actually happens: the same KB and
 * the same versioned prompt rules that ground the receptionist and the analyser,
 * assembled into the Realtime session's instructions.
 *
 * Three things are deliberate:
 *
 *  - The DISCLOSURE goes first and is not negotiable (AI Act Art. 50). It is not
 *    a rule the model may weigh against other rules; it is the first thing said.
 *  - Only retrieved KB text is included. The model is told, in terms, that
 *    anything outside it does not exist — the same grounding contract as
 *    everywhere else. On a call there is no review queue and no undo, so the
 *    grounding IS the safeguard.
 *  - The context version is stamped, so a recording can always be traced to the
 *    exact KB + ruleset that produced it.
 */
class CallInstructionBuilder
{
    public function __construct(
        private KnowledgeRetriever $retriever,
        private PromptRuleSetService $rules,
        private ContextVersion $contextVersion,
    ) {}

    /**
     * @return array{instructions: string, context_version: string}
     */
    public function forCompany(?Company $company, ?string $objective = null): array
    {
        $topic = $this->topic($company, $objective);

        $chunks = collect($this->retriever->retrieve($topic, 8))
            ->map(fn (array $hit): string => trim($hit['chunk']->content))
            ->filter()
            ->values();

        $sections = [];

        // 1. Art. 50 — first, always, verbatim.
        $sections[] = "OPENINGSREGEL — zeg dit als allereerste, woordelijk:\n"
            .config('outbound.disclosure');

        // 2. Who we are and why we called.
        $sections[] = "WIE JE BENT\nJe belt namens Smoothware, een Nederlands web- en softwarebureau.";

        if ($company !== null) {
            $sections[] = $this->companyContext($company);
        }

        if (filled($objective)) {
            $sections[] = "DOEL VAN DIT GESPREK\n{$objective}";
        }

        // 3. The standing rules a human wrote and versioned (Phase 2). Same rules
        //    the receptionist and analyser obey — one place, one version.
        if (filled($ruleText = $this->activeRulesAsText())) {
            $sections[] = "REGELS\n{$ruleText}";
        }

        // 4. The only facts that exist.
        $sections[] = $chunks->isEmpty()
            ? "KENNISBANK\nDe kennisbank gaf geen resultaten voor dit gesprek. Je hebt dus GEEN "
                .'informatie om te delen. Zeg dat eerlijk, beloof niets, en draag het gesprek over '
                .'aan een collega of bied aan terug te bellen.'
            : "KENNISBANK — dit is ALLES wat je weet\n".$chunks->map(fn ($c, $i) => '['.($i + 1)."] {$c}")->implode("\n\n");

        // 5. The hard limits. Repeated here because a live call has no undo.
        $sections[] = <<<'TXT'
        HARDE GRENZEN
        - Verzin niets. Staat het niet hierboven, dan weet je het niet. Zeg dat gewoon.
        - Noem NOOIT een prijs, tarief of schatting. Verwijs naar het gratis gesprek van 30 minuten.
        - Beloof geen deadlines, resultaten of beschikbaarheid.
        - Vraagt iemand om niet meer gebeld te worden: bevestig dat direct, zeg dat je het vastlegt,
          en beëindig het gesprek beleefd. Dit heeft voorrang op elk ander doel.
        - Bij twijfel, een klacht, of iets buiten de kennisbank: draag over aan een mens.
        - Spreek Nederlands, tenzij de ander Engels spreekt.
        TXT;

        return [
            'instructions' => implode("\n\n---\n\n", $sections),
            'context_version' => $this->contextVersion->current(),
        ];
    }

    /** The active ruleset's rules, in order, as plain lines. */
    private function activeRulesAsText(): string
    {
        $set = $this->rules->active();

        if ($set === null) {
            return '';
        }

        return $set->rules()
            ->orderBy('sort_order')
            ->pluck('rule_text')
            ->map(fn (string $text): string => '- '.trim($text))
            ->implode("\n");
    }

    /** What this company is, in the AI's words, from what the CRM already knows. */
    private function companyContext(Company $company): string
    {
        $facts = array_filter([
            "Bedrijf: {$company->name}",
            $company->industry ? "Branche: {$company->industry}" : null,
            $company->city ? "Plaats: {$company->city}" : null,
            $company->domain ? "Website: {$company->domain}" : null,
        ]);

        $context = "WIE JE BELT\n".implode("\n", $facts);

        // The AI's own analysis of their website — real findings, already measured.
        // This is what makes the call informed rather than a cold script.
        $analysis = $company->latestAiAnalysis;

        if ($analysis && filled($analysis->technical)) {
            $findings = collect($analysis->technical)
                ->take(4)
                ->map(fn (array $f): string => '- '.($f['label'] ?? '').': '.($f['assessment'] ?? ''))
                ->implode("\n");

            $context .= "\n\nWAT WE OVER HUN WEBSITE WETEN (feitelijk gemeten):\n{$findings}"
                ."\n\nGebruik dit alleen als het natuurlijk past. Niet opdreunen.";
        }

        return $context;
    }

    private function topic(?Company $company, ?string $objective): string
    {
        return trim(implode(' ', array_filter([
            $objective,
            $company?->industry,
            'diensten websites software prijzen werkwijze',
        ])));
    }
}
