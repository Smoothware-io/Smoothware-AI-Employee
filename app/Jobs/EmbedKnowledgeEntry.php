<?php

namespace App\Jobs;

use App\Contracts\EmbeddingClient;
use App\Models\KnowledgeEntry;
use App\Services\KnowledgeChunker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * (Re)chunks and embeds a knowledge entry. Dispatched whenever an entry's
 * content or status changes. Only PUBLISHED entries end up with chunks — for
 * anything else, existing chunks are cleared so it can never be retrieved.
 */
class EmbedKnowledgeEntry implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $entryId) {}

    public function handle(EmbeddingClient $client, KnowledgeChunker $chunker): void
    {
        $entry = KnowledgeEntry::find($this->entryId);

        if (! $entry) {
            return;
        }

        // Regenerate from scratch — simplest correct approach for a small KB.
        $entry->chunks()->delete();

        if (! $entry->isPublished()) {
            return;
        }

        $texts = $chunker->chunk($entry->embeddableText());

        if ($texts === []) {
            return;
        }

        $vectors = $client->embedBatch($texts);

        foreach ($texts as $index => $content) {
            $entry->chunks()->create([
                'chunk_index' => $index,
                'content' => $content,
                'token_count' => (int) ceil(mb_strlen($content) / 4),
                'embedding' => $vectors[$index],
                'embedding_model' => $client->model(),
                'embedding_dims' => $client->dimensions(),
                'embedded_at' => now(),
            ]);
        }
    }
}
