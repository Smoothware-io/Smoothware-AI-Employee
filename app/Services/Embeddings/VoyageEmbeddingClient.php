<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingClient;
use Illuminate\Support\Facades\Http;

/**
 * Voyage AI embeddings (Anthropic's recommended embeddings partner). Wired in
 * production once VOYAGE_API_KEY is set; the pipeline is otherwise identical to
 * the fake client. Not exercised in tests (no network).
 */
class VoyageEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private string $apiKey,
        private string $model = 'voyage-3',
        private int $dims = 1024,
    ) {}

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        $response = Http::withToken($this->apiKey)
            ->post('https://api.voyageai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => array_values($texts),
            ])
            ->throw()
            ->json();

        return array_map(fn (array $item): array => $item['embedding'], $response['data']);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dims;
    }
}
