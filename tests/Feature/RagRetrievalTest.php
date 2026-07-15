<?php

use App\Enums\PublishStatus;
use App\Models\KnowledgeEntry;
use App\Services\KnowledgeRetriever;

it('embeds a published entry into chunks (via the queued job, sync in tests)', function () {
    $entry = KnowledgeEntry::factory()->published()->create([
        'title' => 'SEO services',
        'body' => 'We improve your search engine ranking and organic traffic.',
    ]);

    expect($entry->chunks()->count())->toBeGreaterThan(0)
        ->and($entry->chunks()->first()->embedding)->toBeArray()
        ->and($entry->chunks()->first()->embedded_at)->not->toBeNull();
});

it('does not embed a draft entry', function () {
    $entry = KnowledgeEntry::factory()->create([
        'status' => PublishStatus::Draft,
        'body' => 'search engine ranking draft content',
    ]);

    expect($entry->chunks()->count())->toBe(0);
});

it('clears chunks when an entry is unpublished', function () {
    $entry = KnowledgeEntry::factory()->published()->create(['body' => 'hosting and maintenance']);
    expect($entry->chunks()->count())->toBeGreaterThan(0);

    $entry->update(['status' => PublishStatus::Draft]);

    expect($entry->fresh()->chunks()->count())->toBe(0);
});

it('ranks the most relevant published entry first', function () {
    KnowledgeEntry::factory()->published()->create([
        'title' => 'SEO',
        'body' => 'search engine optimization ranking keywords organic traffic',
    ]);
    KnowledgeEntry::factory()->published()->create([
        'title' => 'Hosting',
        'body' => 'server uptime hosting maintenance backups',
    ]);

    $results = app(KnowledgeRetriever::class)->retrieve('improve my search engine ranking', 3);

    expect($results)->not->toBeEmpty()
        ->and($results[0]['chunk']->content)->toContain('search engine')
        ->and($results[0]['score'])->toBeGreaterThan(0.0);
});

it('never retrieves chunks from non-published entries', function () {
    KnowledgeEntry::factory()->create([
        'status' => PublishStatus::Draft,
        'body' => 'search engine ranking secret draft',
    ]);

    expect(app(KnowledgeRetriever::class)->retrieve('search engine ranking', 5))->toBeEmpty();
});

it('caps results at k', function () {
    KnowledgeEntry::factory()->published()->count(5)->create(['body' => 'websites design build launch']);

    expect(app(KnowledgeRetriever::class)->retrieve('websites', 2))->toHaveCount(2);
});
