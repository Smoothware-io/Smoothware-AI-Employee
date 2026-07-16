<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingClient;
use App\Enums\EmbeddingInputType;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Voyage AI embeddings (Anthropic's recommended embeddings partner).
 *
 * Verified against Voyage's published API (2026-07-16):
 * `POST https://api.voyageai.com/v1/embeddings`, taking `model`, `input` (max
 * 1000 items), `input_type`, `output_dimension`, `truncation`.
 *
 * Three things this deliberately does that the first cut didn't:
 *
 *  - Sends `input_type`. Voyage prepends a different internal prompt for
 *    documents vs queries, producing vectors tuned for retrieval. Our RAG is
 *    exactly that asymmetric case, so omitting it costs accuracy for free.
 *  - Sends `output_dimension`, so the vector we get back is the size we claim it
 *    is. Previously `dimensions()` was read from config while the API returned
 *    whatever it liked — metadata that could quietly lie.
 *  - VERIFIES the returned vector length and fails loudly on a mismatch. A
 *    wrong-length vector poisons cosine similarity in a way nothing downstream
 *    would notice.
 *
 * `truncation` is left at Voyage's default (true): a KB chunk over the model's
 * limit should be clipped, not blow up the whole batch.
 */
class VoyageEmbeddingClient implements EmbeddingClient
{
    private const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

    /** Voyage accepts at most 1000 inputs per request. */
    private const MAX_BATCH = 1000;

    public function __construct(
        private string $apiKey,
        private string $model = 'voyage-3.5',
        private int $dims = 1024,
    ) {}

    public function embed(string $text, EmbeddingInputType $type): array
    {
        return $this->embedBatch([$text], $type)[0];
    }

    public function embedBatch(array $texts, EmbeddingInputType $type): array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk($texts, self::MAX_BATCH) as $batch) {
            $vectors = [...$vectors, ...$this->request($batch, $type)];
        }

        return $vectors;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function request(array $texts, EmbeddingInputType $type): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->retry(3, 500, throw: false)
            ->post(self::ENDPOINT, [
                'model' => $this->model,
                'input' => $texts,
                'input_type' => $type->value,
                'output_dimension' => $this->dims,
            ])
            ->throw()
            ->json();

        $data = $response['data'] ?? null;

        if (! is_array($data) || count($data) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'Voyage returned %s embeddings for %d inputs.',
                is_array($data) ? count($data) : 'no',
                count($texts),
            ));
        }

        // Voyage documents that `data` comes back in input order; assert it, since
        // a silent reorder would attach every vector to the wrong chunk.
        usort($data, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(function (array $item): array {
            $vector = $item['embedding'] ?? [];

            if (count($vector) !== $this->dims) {
                throw new RuntimeException(sprintf(
                    'Voyage returned a %d-dimension vector but %s is configured for %d. '.
                    'Fix VOYAGE_DIMENSIONS before embedding, or every stored dimension is wrong.',
                    count($vector),
                    $this->model,
                    $this->dims,
                ));
            }

            return $vector;
        }, $data);
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
