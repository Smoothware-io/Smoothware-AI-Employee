<?php

namespace Database\Seeders;

use App\Enums\KnowledgeType;
use App\Enums\PublishStatus;
use App\Enums\RecordSource;
use App\Models\KnowledgeEntry;
use App\Models\PromptRule;
use App\Models\PromptRuleSet;
use Illuminate\Database\Seeder;

/**
 * Starter knowledge base, drawn from smoothware.nl's ACTUAL published content
 * (fetched 2026-07-16; smoothware.io 301-redirects there).
 *
 * Why this exists: with an empty KB the grounding enforcement correctly refuses
 * to say anything — a live AI would transfer every call and every analysis would
 * return "no KB-grounded recommendations". This is the floor the AI stands on.
 *
 * It is a STARTING POINT, not the real thing. Two deliberate properties:
 *
 *  - Entries are seeded as DRAFT with `last_verified_at = null`, so they are
 *    stale by definition and NOT retrievable until a human reads and publishes
 *    them. Seeding published content would mean an AI quoting text to customers
 *    that nobody at Smoothware ever approved.
 *  - Content is in Dutch, because the source is and the market is. The AI should
 *    speak the prospect's language, not ours.
 *
 * Nothing here contains personal data — services, pricing posture, process, FAQ.
 * No named clients, no case studies (see GO-LIVE-LEGAL §5 before adding any).
 */
class SmoothwareKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->entries() as $entry) {
            KnowledgeEntry::updateOrCreate(
                ['title' => $entry['title']],
                [
                    'type' => $entry['type'],
                    'body' => trim($entry['body']),
                    'data' => $entry['data'] ?? null,
                    // Draft on purpose — a human must verify before the AI can quote it.
                    'status' => PublishStatus::Draft,
                    'last_verified_at' => null,
                    'source' => RecordSource::System,
                ],
            );
        }

        $this->seedPromptRules();
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(): array
    {
        return [
            [
                'type' => KnowledgeType::CompanyInfo,
                'title' => 'Over Smoothware',
                'body' => <<<'TXT'
                Smoothware is een Nederlands web- en softwarebureau. Wij worden vertrouwd door
                ambitieuze ondernemers door heel Nederland.

                Onze aanpak is "strategie first": we beginnen met de vraag wat het bedrijf nodig
                heeft, niet met de techniek. Tijdens het traject zie je doorlopend ontwerpen en
                demo's, zodat je continu kunt bijsturen.

                Werkgebied: Nederland. Voertaal: Nederlands (Engels kan ook).
                TXT,
            ],
            [
                'type' => KnowledgeType::Service,
                'title' => 'Diensten: wat Smoothware levert',
                'body' => <<<'TXT'
                Smoothware levert negen diensten:

                1. AI Automation — slimme AI-workflows en chatbots die repetitief werk automatiseren.
                2. Websites — maatwerk websites die jouw merk laten groeien en leads genereren.
                3. SEO — organisch vindbaar worden bij de juiste doelgroep.
                4. SEA — advertenties via Google Ads en social media.
                5. Branding — logo en merkidentiteit.
                6. E-commerce — gebruiksvriendelijke webshops die verkopen stimuleren.
                7. Dev Teams — flexibele development-ondersteuning, van concept tot livegang.
                8. Custom Software — maatwerkoplossingen om processen te automatiseren.
                9. Mobile Applications — apps voor iOS en Android.
                TXT,
                'data' => ['services' => [
                    'ai_automation', 'websites', 'seo', 'sea', 'branding',
                    'ecommerce', 'dev_teams', 'custom_software', 'mobile_apps',
                ]],
            ],
            [
                'type' => KnowledgeType::Pricing,
                'title' => 'Prijzen en offertes',
                'body' => <<<'TXT'
                Onze websites starten vanaf een helder instaptarief. Je krijgt altijd een vaste
                offerte — geen verrassingen achteraf.

                Een gratis kennismakingsgesprek van 30 minuten maakt de kosten duidelijk.

                LET OP voor de AI: er staan GEEN concrete bedragen op de website. Noem nooit een
                prijs, tarief of schatting. Verwijs naar het gratis gesprek voor een vaste offerte.
                TXT,
                'data' => ['quote_type' => 'fixed', 'intro_call_minutes' => 30, 'published_amounts' => false],
            ],
            [
                'type' => KnowledgeType::Process,
                'title' => 'Werkwijze en planning',
                'body' => <<<'TXT'
                1. Strategie first — we starten met doel en doelgroep, niet met techniek.
                2. Ontwerp en demo's — je ziet het werk tijdens het traject en geeft doorlopend feedback.
                3. Planning — je krijgt vooraf een realistische planning.
                4. Livegang en optimalisatie — na livegang blijven we optimaliseren.

                Doorlooptijd: een landingspagina kan in 2 weken live. Complexere websites duren langer.
                TXT,
                'data' => ['landing_page_weeks' => 2],
            ],
            [
                'type' => KnowledgeType::Faq,
                'title' => 'Hoe snel kan mijn website live zijn?',
                'body' => <<<'TXT'
                Een landingspagina kan in ongeveer 2 weken live. Complexere websites en webshops
                duren langer — dat hangt af van de omvang en de content.

                Je krijgt vooraf een realistische planning, zodat je weet waar je aan toe bent.
                TXT,
            ],
            [
                'type' => KnowledgeType::Faq,
                'title' => 'Wat gebeurt er na de livegang?',
                'body' => <<<'TXT'
                Na livegang blijven we optimaliseren. Er zijn onderhoudspakketten en support
                beschikbaar.

                Je krijgt een gebruiksvriendelijk CMS waarmee je zelf content kunt aanpassen,
                inclusief uitleg en training.
                TXT,
            ],
        ];
    }

    /**
     * The AI's standing instructions, versioned via PromptRuleSet (Phase 2).
     * Without an active set, ContextVersion reports `rules:none` and the AI runs
     * with no operating rules at all.
     */
    private function seedPromptRules(): void
    {
        if (PromptRuleSet::query()->where('status', 'active')->exists()) {
            return; // never silently replace a live ruleset
        }

        $set = PromptRuleSet::create([
            'version' => (int) (PromptRuleSet::max('version') ?? 0) + 1,
            'status' => 'active',
            'notes' => 'Baseline operating rules, seeded from smoothware.nl content.',
            'activated_at' => now(),
        ]);

        $rules = [
            ['grounding', 'Beantwoord uitsluitend op basis van de knowledge base. Verzin niets. Als de KB geen antwoord bevat, zeg dat je het navraagt en draag over aan een mens.'],
            ['pricing', 'Noem nooit bedragen, tarieven of schattingen. Smoothware publiceert geen prijzen. Verwijs naar het gratis kennismakingsgesprek van 30 minuten voor een vaste offerte.'],
            ['language', 'Spreek de taal van de prospect. Nederlandse bedrijven: Nederlands, tenzij zij zelf Engels spreken.'],
            ['honesty', 'Doe geen toezeggingen over deadlines, resultaten of beschikbaarheid die niet in de KB staan.'],
            ['handover', 'Bij twijfel, bij een klacht, of bij een vraag buiten de KB: draag over aan een mens.'],
        ];

        foreach ($rules as $index => [$category, $ruleText]) {
            PromptRule::create([
                'prompt_rule_set_id' => $set->id,
                'category' => $category,
                'rule_text' => $ruleText,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
