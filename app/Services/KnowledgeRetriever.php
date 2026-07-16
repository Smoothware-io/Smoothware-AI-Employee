<?php

namespace App\Services;

use App\Contracts\EmbeddingClient;
use App\Enums\EmbeddingInputType;
use App\Enums\PublishStatus;
use App\Models\KnowledgeChunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retrieves the most relevant knowledge chunks for a query. Embeds the query,
 * then brute-force cosine-ranks it against every published chunk in PHP (fine
 * for a single agency's KB; see ARCHITECTURE "Open decisions"). The score is the
 * Phase-3 grounding signal: below a threshold, the AI should defer to a human
 * rather than improvise.
 *
 * ONLY RANKS CHUNKS EMBEDDED BY THE ACTIVE MODEL. Vectors from different models
 * share no geometry — a Voyage query vector scored against a leftover fake
 * bag-of-words vector produces noise, not a low score. Without this filter,
 * switching EMBEDDINGS_DRIVER would silently corrupt retrieval: no error, no
 * failing test, just the AI confidently retrieving the wrong entry or falling
 * back on questions it should answer easily. Stale chunks are ignored and
 * counted, never scored — see {@see staleChunkCount()}, surfaced on the
 * dashboard and fixed with `php artisan kb:reembed`.
 */
class KnowledgeRetriever
{
    public function __construct(private EmbeddingClient $client) {}

    /**
     * @return array<int, array{chunk: KnowledgeChunk, score: float}>
     */
    public function retrieve(string $query, int $k = 5): array
    {
        // A question, not a document — Voyage tunes the vector differently.
        $queryVector = $this->client->embed($query, EmbeddingInputType::Query);

        return $this->comparableChunks()
            ->get()
            ->map(fn (KnowledgeChunk $chunk): array => [
                'chunk' => $chunk,
                'score' => $this->cosine($queryVector, $chunk->embedding ?? []),
            ])
            ->sortByDesc('score')
            ->take($k)
            ->values()
            ->all();
    }

    /** Published chunks embedded by the model currently bound. */
    public function comparableChunks(): Builder
    {
        return KnowledgeChunk::query()
            ->whereNotNull('embedding')
            ->where('embedding_model', $this->client->model())
            ->whereHas('entry', fn ($q) => $q->where('status', PublishStatus::Published->value));
    }

    /**
     * Published chunks embedded by some OTHER model — invisible to retrieval
     * until re-embedded. Non-zero means part of the KB is dark.
     */
    public function staleChunkCount(): int
    {
        return KnowledgeChunk::query()
            ->whereNotNull('embedding')
            ->where(fn ($q) => $q->where('embedding_model', '!=', $this->client->model())
                ->orWhereNull('embedding_model'))
            ->whereHas('entry', fn ($q) => $q->where('status', PublishStatus::Published->value))
            ->count();
    }

    public function activeModel(): string
    {
        return $this->client->model();
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
