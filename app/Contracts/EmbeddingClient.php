<?php

namespace App\Contracts;

use App\Services\Embeddings\FakeEmbeddingClient;

/**
 * Text embedding provider. Anthropic's API is generation-only, so RAG needs a
 * separate embeddings provider (we intend Voyage AI). This interface keeps the
 * pipeline provider-agnostic — the whole thing is built and tested against
 * {@see FakeEmbeddingClient} with no API key.
 */
interface EmbeddingClient
{
    /**
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> vectors in the same order as $texts
     */
    public function embedBatch(array $texts): array;

    public function model(): string;

    public function dimensions(): int;
}
