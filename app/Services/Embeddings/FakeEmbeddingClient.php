<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingClient;

/**
 * Deterministic, offline embedding client for local dev, tests and CI — no API
 * key, no network. Uses the hashing trick (bag-of-words over a fixed dimension),
 * so texts that share words get a higher cosine similarity. Good enough to prove
 * the retrieval pipeline ranks correctly; swapped for Voyage in production.
 */
class FakeEmbeddingClient implements EmbeddingClient
{
    public function __construct(private int $dims = 256) {}

    public function embed(string $text): array
    {
        $vector = array_fill(0, $this->dims, 0.0);

        foreach ($this->tokenize($text) as $token) {
            $vector[crc32($token) % $this->dims] += 1.0;
        }

        return $this->normalize($vector);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $text): array => $this->embed($text), array_values($texts));
    }

    public function model(): string
    {
        return "fake-hash-{$this->dims}";
    }

    public function dimensions(): int
    {
        return $this->dims;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: []));
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $vector)));

        return $norm > 0.0 ? array_map(fn (float $x): float => $x / $norm, $vector) : $vector;
    }
}
