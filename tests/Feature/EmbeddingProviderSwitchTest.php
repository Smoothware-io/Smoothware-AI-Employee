<?php

use App\Contracts\EmbeddingClient;
use App\Enums\EmbeddingInputType;
use App\Enums\PublishStatus;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeEntry;
use App\Services\Embeddings\VoyageEmbeddingClient;
use App\Services\KnowledgeRetriever;
use Illuminate\Support\Facades\Http;

/**
 * Switching embeddings provider is the moment this system is most likely to
 * break QUIETLY: vectors from different models share no geometry, so a real
 * query scored against leftover fake vectors returns noise — not an error, not a
 * low score, just the wrong chunks. Nothing else in the suite would notice.
 */
function chunkEmbeddedBy(string $model, array $vector): KnowledgeChunk
{
    $entry = KnowledgeEntry::factory()->create(['status' => PublishStatus::Published]);

    // Publishing auto-dispatches EmbedKnowledgeEntry (sync queue in tests), so the
    // entry already has real fake-model chunks. Drop them: this helper is about
    // planting a chunk with a KNOWN provenance.
    $entry->chunks()->delete();

    return $entry->chunks()->create([
        'chunk_index' => 0,
        'content' => 'Our websites start at a fixed project fee.',
        'token_count' => 10,
        'embedding' => $vector,
        'embedding_model' => $model,
        'embedding_dims' => count($vector),
        'embedded_at' => now(),
    ]);
}

// --- The load-bearing guard ------------------------------------------------

it('never scores a chunk embedded by a different model', function () {
    $retriever = app(KnowledgeRetriever::class);
    $active = $retriever->activeModel(); // fake-hash-256 in tests

    // A chunk left behind by another provider. Its vector is meaningless here.
    chunkEmbeddedBy('voyage-3.5', array_fill(0, 1024, 0.1));

    expect($retriever->retrieve('what does a website cost'))->toBeEmpty()
        ->and($retriever->staleChunkCount())->toBe(1)
        ->and($retriever->comparableChunks()->count())->toBe(0)
        ->and($active)->toContain('fake');
});

it('retrieves chunks embedded by the active model', function () {
    $retriever = app(KnowledgeRetriever::class);
    $client = app(EmbeddingClient::class);

    chunkEmbeddedBy(
        $client->model(),
        $client->embed('Our websites start at a fixed project fee.', EmbeddingInputType::Document),
    );

    $results = $retriever->retrieve('what does a website cost');

    expect($results)->toHaveCount(1)
        ->and($results[0]['score'])->toBeGreaterThan(0.0)
        ->and($retriever->staleChunkCount())->toBe(0);
});

it('counts a chunk with no recorded model as stale rather than trusting it', function () {
    // Pre-dates the model stamp: unknown provenance, so not comparable.
    chunkEmbeddedBy('fake-hash-256', array_fill(0, 256, 0.1))
        ->update(['embedding_model' => null]);

    expect(app(KnowledgeRetriever::class)->staleChunkCount())->toBe(1);
});

it('ignores stale chunks from unpublished entries', function () {
    $entry = KnowledgeEntry::factory()->create(['status' => PublishStatus::Draft]);
    $entry->chunks()->delete();
    $entry->chunks()->create([
        'chunk_index' => 0,
        'content' => 'draft',
        'token_count' => 1,
        'embedding' => array_fill(0, 1024, 0.1),
        'embedding_model' => 'voyage-3.5',
        'embedding_dims' => 1024,
        'embedded_at' => now(),
    ]);

    // Unpublished content never feeds RAG, so it cannot be "dark".
    expect(app(KnowledgeRetriever::class)->staleChunkCount())->toBe(0);
});

// --- The Voyage client's request shape -------------------------------------

it('sends input_type and output_dimension to Voyage', function () {
    Http::fake(['api.voyageai.com/*' => Http::response([
        'data' => [['index' => 0, 'embedding' => array_fill(0, 1024, 0.01)]],
    ])]);

    (new VoyageEmbeddingClient('key', 'voyage-3.5', 1024))
        ->embed('what does a website cost', EmbeddingInputType::Query);

    Http::assertSent(function ($request) {
        // Asymmetric retrieval: Voyage tunes the vector by input_type.
        return $request['model'] === 'voyage-3.5'
            && $request['input_type'] === 'query'
            && $request['output_dimension'] === 1024
            && $request['input'] === ['what does a website cost'];
    });
});

it('sends document as the input type when embedding stored content', function () {
    Http::fake(['api.voyageai.com/*' => Http::response([
        'data' => [['index' => 0, 'embedding' => array_fill(0, 1024, 0.01)]],
    ])]);

    (new VoyageEmbeddingClient('key', 'voyage-3.5', 1024))
        ->embedBatch(['a chunk of the knowledge base'], EmbeddingInputType::Document);

    Http::assertSent(fn ($request) => $request['input_type'] === 'document');
});

it('refuses a vector whose length is not what we claim to store', function () {
    // The metadata lie: config says 1024, Voyage returns 512. Storing that would
    // poison every future cosine comparison.
    Http::fake(['api.voyageai.com/*' => Http::response([
        'data' => [['index' => 0, 'embedding' => array_fill(0, 512, 0.01)]],
    ])]);

    expect(fn () => (new VoyageEmbeddingClient('key', 'voyage-3.5', 1024))
        ->embed('hello', EmbeddingInputType::Query))
        ->toThrow(RuntimeException::class, '512-dimension vector');
});

it('rejects a response that returns the wrong number of embeddings', function () {
    Http::fake(['api.voyageai.com/*' => Http::response([
        'data' => [['index' => 0, 'embedding' => array_fill(0, 1024, 0.01)]],
    ])]);

    expect(fn () => (new VoyageEmbeddingClient('key', 'voyage-3.5', 1024))
        ->embedBatch(['one', 'two'], EmbeddingInputType::Document))
        ->toThrow(RuntimeException::class, '1 embeddings for 2 inputs');
});

it('restores input order so vectors cannot attach to the wrong chunk', function () {
    Http::fake(['api.voyageai.com/*' => Http::response([
        'data' => [
            ['index' => 1, 'embedding' => array_fill(0, 4, 0.2)],
            ['index' => 0, 'embedding' => array_fill(0, 4, 0.1)],
        ],
    ])]);

    $vectors = (new VoyageEmbeddingClient('key', 'voyage-3.5', 4))
        ->embedBatch(['first', 'second'], EmbeddingInputType::Document);

    expect($vectors[0][0])->toBe(0.1)
        ->and($vectors[1][0])->toBe(0.2);
});

// --- The commands -----------------------------------------------------------

it('reports coverage and warns when the KB is partly dark', function () {
    chunkEmbeddedBy('voyage-3.5', array_fill(0, 1024, 0.1));

    $this->artisan('kb:reembed --status')
        ->expectsOutputToContain('fake-hash-256')
        ->assertExitCode(0);
});

it('fails the health check while chunks remain unretrievable', function () {
    chunkEmbeddedBy('voyage-3.5', array_fill(0, 1024, 0.1));

    // The fake client always answers, so the ONLY thing that can fail here is the
    // staleness check — which is the point.
    $this->artisan('embeddings:health')->assertExitCode(1);
});

it('passes the health check when everything matches the active model', function () {
    $client = app(EmbeddingClient::class);
    chunkEmbeddedBy($client->model(), $client->embed('pricing', EmbeddingInputType::Document));

    $this->artisan('embeddings:health')->assertExitCode(0);
});

it('refuses to run against voyage with no api key', function () {
    config(['services.embeddings.driver' => 'voyage', 'services.embeddings.voyage.key' => null]);

    $this->artisan('embeddings:health')
        ->expectsOutputToContain('VOYAGE_API_KEY is empty')
        ->assertExitCode(1);
});
