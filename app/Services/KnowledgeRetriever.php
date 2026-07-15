<?php

namespace App\Services;

use App\Contracts\EmbeddingClient;
use App\Enums\PublishStatus;
use App\Models\KnowledgeChunk;

/**
 * Retrieves the most relevant knowledge chunks for a query. Embeds the query,
 * then brute-force cosine-ranks it against every published chunk in PHP (fine
 * for a single agency's KB; see ARCHITECTURE “Open decisions”). The score is the Phase-3
 * grounding signal: below a threshold, the AI should defer to a human rather
 * than improvise.
 */
class KnowledgeRetriever
{
    public function __construct(private EmbeddingClient $client) {}

    /**
     * @return array<int, array{chunk: KnowledgeChunk, score: float}>
     */
    public function retrieve(string $query, int $k = 5): array
    {
        $queryVector = $this->client->embed($query);

        $chunks = KnowledgeChunk::query()
            ->whereNotNull('embedding')
            ->whereHas('entry', fn ($q) => $q->where('status', PublishStatus::Published->value))
            ->get();

        return $chunks
            ->map(fn (KnowledgeChunk $chunk): array => [
                'chunk' => $chunk,
                'score' => $this->cosine($queryVector, $chunk->embedding ?? []),
            ])
            ->sortByDesc('score')
            ->take($k)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $normA += $x * $x;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
