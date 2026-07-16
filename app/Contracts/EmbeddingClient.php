<?php

namespace App\Contracts;

use App\Enums\EmbeddingInputType;
use App\Services\Embeddings\FakeEmbeddingClient;
use App\Services\KnowledgeRetriever;

/**
 * Text embedding provider. Anthropic's API is generation-only, so RAG needs a
 * separate embeddings provider (Voyage AI). This interface keeps the pipeline
 * provider-agnostic — the whole thing is built and tested against
 * {@see FakeEmbeddingClient} with no API key.
 *
 * `$type` is REQUIRED rather than defaulted: retrieval is asymmetric (documents
 * in, queries out) and getting it wrong degrades results silently.
 */
interface EmbeddingClient
{
    /**
     * @return array<int, float>
     */
    public function embed(string $text, EmbeddingInputType $type): array;

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> vectors in the same order as $texts
     */
    public function embedBatch(array $texts, EmbeddingInputType $type): array;

    /**
     * The identifier stamped onto every chunk. Vectors from DIFFERENT models are
     * not comparable at all, so this is what lets {@see KnowledgeRetriever}
     * refuse to mix them instead of silently scoring noise.
     */
    public function model(): string;

    public function dimensions(): int;
}
