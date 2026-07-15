<?php

namespace App\Services\Receptionist;

use App\Contracts\ReceptionistLlm;
use App\Enums\CallIntent;
use App\Support\Receptionist\ReceptionistAnalysis;
use Illuminate\Support\Str;

/**
 * Deterministic, offline stand-in for Claude (dev/tests/CI). Derives intent from
 * keywords and cites the chunks it was given, so grounding behaviour is testable
 * without an API key. When no chunks are provided it produces no grounded answer
 * (which the orchestrator turns into fallback_to_human).
 */
class FakeReceptionistLlm implements ReceptionistLlm
{
    public function analyze(string $transcript, array $chunks, array $rules): ReceptionistAnalysis
    {
        $lower = mb_strtolower($transcript);

        $intent = match (true) {
            $this->mentions($lower, ['website', 'seo', 'app', 'price', 'quote', 'proposal']) => CallIntent::SalesInquiry,
            $this->mentions($lower, ['invoice', 'support', 'issue', 'broken', 'help']) => CallIntent::Support,
            $this->mentions($lower, ['existing', 'account', 'my project']) => CallIntent::ExistingCustomer,
            default => CallIntent::Other,
        };

        $citedIds = array_map(fn (array $c): int => $c['id'], $chunks);
        $answer = $chunks !== []
            ? 'Based on our knowledge base: '.Str::limit($chunks[0]['content'], 160)
            : null;

        return new ReceptionistAnalysis(
            intent: $intent,
            summary: Str::limit(trim($transcript), 200),
            answer: $answer,
            usedChunkIds: $citedIds,
            companyName: $this->guessCompany($transcript),
            proposedTaskTitle: $intent === CallIntent::SalesInquiry ? 'Follow up on inbound sales call' : null,
            confidence: $chunks !== [] ? 0.8 : 0.4,
            inputTokens: str_word_count($transcript),
            outputTokens: 50,
        );
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function guessCompany(string $transcript): ?string
    {
        if (preg_match('/\b(?:from|at|with|for)\s+([A-Z][A-Za-z0-9&.\- ]{2,40}?)(?:\.|,|\s+(?:and|about|regarding)|$)/', $transcript, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
