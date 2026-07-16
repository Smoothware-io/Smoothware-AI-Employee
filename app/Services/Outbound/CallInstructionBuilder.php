<?php

namespace App\Services\Outbound;

use App\Enums\CallDirection;
use App\Enums\Language;
use App\Enums\PreferredChannel;
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
    /**
     * @param  CallDirection  $direction  who dialled whom. Every line below changes
     *                                    with it: an AI that ANSWERS must not
     *                                    announce a call, and an AI that CALLS must
     *                                    not ask "how can I help you?".
     */
    public function forCompany(
        ?Company $company,
        ?string $objective = null,
        CallDirection $direction = CallDirection::Outbound,
    ): array {
        $inbound = $direction === CallDirection::Inbound;
        $topic = $this->topic($company, $objective);

        $chunks = collect($this->retriever->retrieve($topic, 8))
            ->map(fn (array $hit): string => trim($hit['chunk']->content))
            ->filter()
            ->values();

        $sections = [];

        // 1. Art. 50 — first, always, before anything else.
        //
        // Delivered in the CALLER'S language rather than recited verbatim in
        // Dutch: the obligation is that the person UNDERSTANDS they are talking to
        // a machine, and Dutch at an English speaker fails that while looking
        // compliant. The meaning is fixed; the language is not.
        $sections[] = "OPENINGSREGEL — zeg dit als allereerste, vóór al het andere.\n"
            .'Geef exact deze boodschap, in de taal van de '.($inbound ? 'beller' : 'gebelde persoon').":\n\n"
            .($inbound ? config('receptionist.ai_disclosure') : config('outbound.disclosure'))
            ."\n\nVerzwak of versnel dit nooit, en sla het nooit over — ook niet als "
            .'de ander haast heeft.';

        // 2. Who we are, and which way this call goes.
        $sections[] = $inbound
            ? "WIE JE BENT\nJe NEEMT DE TELEFOON OP namens Smoothware, een Nederlands web- en "
                .'softwarebureau. Deze persoon belt ONS — jij belt hen niet. Vraag waarmee je kunt '
                .'helpen, luister, en beantwoord alleen wat je uit de kennisbank weet.'
            : "WIE JE BENT\nJe BELT namens Smoothware, een Nederlands web- en softwarebureau. "
                .'Jij hebt hen gebeld — respecteer hun tijd.';

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

        // Language is per-company, and "we don't know" is a real answer: better to
        // ask than to confidently address someone in the wrong language.
        $language = $company->spokenLanguage();
        $context .= "\n\nTAAL: ".($language?->instruction()
            ?? 'Onbekend. Begin in het Nederlands en vraag welke taal zij prefereren.');

        // A contact who told us they prefer email is still being phoned. Say so —
        // the preference says HOW, not WHETHER, but ignoring it silently is rude
        // and is exactly how someone ends up on the do-not-contact list.
        $emailPreferrers = $company->contacts()
            ->where('preferred_channel', PreferredChannel::Email->value)
            ->exists();

        if ($emailPreferrers) {
            $context .= "\n\nLET OP: bij dit bedrijf staat genoteerd dat men liever per e-mail "
                .'contact heeft. Erken dat, houd het kort, en bied aan het per e-mail te sturen.';
        }

        // 1. THE HUMAN'S JUDGMENT FIRST. A rep who knows this lead wrote this;
        //    the AI is a guest in their account, not the analyst of record.
        $manual = $company->manualAnalysis;

        if ($manual !== null) {
            $lines = collect([
                'Pijnpunten' => $manual->pain_points,
                'Kansen' => $manual->opportunities,
                'Notities' => $manual->notes,
                'Prioriteit' => $manual->priority?->getLabel(),
            ])->filter(fn ($value): bool => filled($value))
                ->map(fn ($value, $label): string => "- {$label}: {$value}")
                ->implode("\n");

            if ($lines !== '') {
                $context .= "\n\nWAT ONS TEAM OVER DEZE LEAD ZEGT (door een mens geschreven — "
                    ."dit is je belangrijkste sturing):\n{$lines}";
            }
        }

        // 2. Then what the machine measured. Facts, but secondary.
        $analysis = $company->latestAiAnalysis;

        if ($analysis && filled($analysis->technical)) {
            $findings = collect($analysis->technical)
                ->take(4)
                ->map(fn (array $f): string => '- '.($f['label'] ?? '').': '.($f['assessment'] ?? ''))
                ->implode("\n");

            $context .= "\n\nWAT WIJ AUTOMATISCH GEMETEN HEBBEN op hun website:\n{$findings}"
                ."\n\nGebruik dit alleen als het natuurlijk past. Niet opdreunen.";
        }

        // 3. The rule that makes principle #2 real on a live call: where the human
        //    and the machine disagree, the human wins. Without this the AI would
        //    argue its own PageSpeed number over a rep who has met these people.
        if ($manual !== null && $analysis !== null) {
            $context .= "\n\nALS de notities van ons team en onze metingen elkaar tegenspreken: "
                .'volg ons team. Zij kennen deze klant, de meting is maar een momentopname.';
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
