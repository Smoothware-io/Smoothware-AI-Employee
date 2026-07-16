<?php

namespace App\Console\Commands;

use App\Contracts\EmbeddingClient;
use App\Enums\EmbeddingInputType;
use App\Services\Embeddings\FakeEmbeddingClient;
use App\Services\KnowledgeRetriever;
use Illuminate\Console\Command;
use Throwable;

/**
 * Makes ONE real embedding call and checks the answer is usable.
 *
 * Exists so "is Voyage actually working?" has an answer that isn't a shrug. The
 * failure mode this guards against is not an exception — it's a provider that
 * responds happily with vectors of the wrong shape, which poisons cosine
 * similarity in a way nothing downstream notices.
 */
class CheckEmbeddingsHealth extends Command
{
    protected $signature = 'embeddings:health';

    protected $description = 'Verify the configured embeddings provider responds with usable vectors';

    public function handle(EmbeddingClient $client, KnowledgeRetriever $retriever): int
    {
        $driver = config('services.embeddings.driver', 'fake');

        $this->components->info("Driver: {$driver} | model: {$client->model()} | expects {$client->dimensions()} dims");

        if ($client instanceof FakeEmbeddingClient) {
            $this->components->warn('This is the OFFLINE FAKE. It always passes and proves nothing about a real provider.');
            $this->components->warn('Set EMBEDDINGS_DRIVER=voyage and VOYAGE_API_KEY to test for real.');
        }

        if ($driver === 'voyage' && blank(config('services.embeddings.voyage.key'))) {
            $this->components->error('EMBEDDINGS_DRIVER=voyage but VOYAGE_API_KEY is empty.');

            return self::FAILURE;
        }

        try {
            $started = microtime(true);
            $query = $client->embed('What does Smoothware charge for a website?', EmbeddingInputType::Query);
            $document = $client->embed('Our websites start at a fixed project fee.', EmbeddingInputType::Document);
            $ms = (int) ((microtime(true) - $started) * 1000);
        } catch (Throwable $e) {
            $this->components->error('Embedding call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $problems = [];

        if (count($query) !== $client->dimensions()) {
            $problems[] = sprintf('query vector is %d dims, expected %d', count($query), $client->dimensions());
        }

        if (count($document) !== count($query)) {
            $problems[] = 'query and document vectors differ in length — they can never be compared';
        }

        // An all-zero vector scores 0.0 against everything: retrieval would look
        // "working" while returning nothing useful.
        if (array_sum(array_map('abs', $query)) <= 0.0) {
            $problems[] = 'query vector is all zeros';
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('OK — %d-dim vectors in %dms.', count($query), $ms));

        $stale = $retriever->staleChunkCount();

        if ($stale > 0) {
            $this->components->warn("{$stale} published chunk(s) were embedded by another model and are INVISIBLE to retrieval.");
            $this->components->warn('Run: php artisan kb:reembed');

            return self::FAILURE;
        }

        $this->components->info('Every published chunk is retrievable with the active model.');

        return self::SUCCESS;
    }
}
