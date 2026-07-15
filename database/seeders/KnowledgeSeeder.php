<?php

namespace Database\Seeders;

use App\Enums\KnowledgeType;
use App\Enums\PublishStatus;
use App\Models\KnowledgeEntry;
use App\Models\PromptRule;
use App\Models\PromptRuleSet;
use App\Services\PromptRuleSetService;
use Illuminate\Database\Seeder;

/**
 * A small, realistic starter knowledge base + prompt ruleset so the RAG pipeline
 * and the Phase 3 AI have something to stand on.
 *   php artisan db:seed --class=KnowledgeSeeder
 */
class KnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            [KnowledgeType::CompanyInfo, 'About Smoothware', 'Smoothware is a web and software agency building websites, mobile apps, SEO, hosting and maintenance for small and mid-sized businesses.'],
            [KnowledgeType::Service, 'Website design & development', 'We design and build fast, modern marketing websites and web apps, including custom software when off-the-shelf will not do.'],
            [KnowledgeType::Service, 'SEO', 'We improve your search engine ranking and organic traffic through technical SEO, content and on-page optimisation.'],
            [KnowledgeType::Service, 'Hosting & maintenance', 'Managed hosting with monitoring, backups, security updates and ongoing maintenance so your site stays fast and online.'],
            [KnowledgeType::Faq, 'How long does a website take?', 'A typical marketing website takes four to eight weeks depending on scope and content readiness.'],
            [KnowledgeType::Pricing, 'Pricing guidelines', 'Pricing depends on scope, integrations and timeline. Give a range and the factors that move it; never quote a single fixed price without a scoping call.'],
        ];

        foreach ($entries as [$type, $title, $body]) {
            KnowledgeEntry::firstOrCreate(
                ['title' => $title],
                [
                    'type' => $type,
                    'body' => $body,
                    'status' => PublishStatus::Published,
                    'last_verified_at' => now(),
                ],
            );
        }

        if (PromptRuleSet::count() === 0) {
            $set = PromptRuleSet::create(['version' => 1, 'notes' => 'Initial ruleset.']);

            $rules = [
                ['pricing', 'Never promise a fixed price; give a range and offer a scoping call.'],
                ['meetings', 'Always offer a meeting for custom software or unclear requirements.'],
                ['honesty', 'Never guess. If the knowledge base lacks the answer, offer a human follow-up.'],
            ];

            foreach ($rules as $i => [$category, $text]) {
                PromptRule::create([
                    'prompt_rule_set_id' => $set->id,
                    'category' => $category,
                    'rule_text' => $text,
                    'sort_order' => $i,
                ]);
            }

            app(PromptRuleSetService::class)->activate($set);
        }
    }
}
