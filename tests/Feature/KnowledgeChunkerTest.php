<?php

use App\Services\KnowledgeChunker;

it('keeps short text as a single chunk', function () {
    $chunks = (new KnowledgeChunker)->chunk('A short knowledge entry.');

    expect($chunks)->toBe(['A short knowledge entry.']);
});

it('collapses whitespace and returns nothing for blank text', function () {
    expect((new KnowledgeChunker)->chunk("   \n\t  "))->toBe([]);
});

it('splits long text into multiple chunks within the size budget', function () {
    $chunker = new KnowledgeChunker(maxChars: 100, overlap: 20);
    $chunks = $chunker->chunk(str_repeat('word ', 100)); // ~500 chars

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(100);
    }
});
