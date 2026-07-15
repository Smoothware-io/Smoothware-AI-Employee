<?php

namespace App\Services;

/**
 * Splits a knowledge entry's text into overlapping chunks for embedding. Short
 * entries (most FAQs) stay a single chunk; longer ones are split on a character
 * budget with a small overlap so context isn't lost at boundaries.
 */
class KnowledgeChunker
{
    public function __construct(
        private int $maxChars = 1500,
        private int $overlap = 200,
    ) {}

    /**
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return [];
        }

        $length = mb_strlen($text);

        if ($length <= $this->maxChars) {
            return [$text];
        }

        $chunks = [];
        $step = max(1, $this->maxChars - $this->overlap);

        for ($start = 0; $start < $length; $start += $step) {
            $chunks[] = trim(mb_substr($text, $start, $this->maxChars));
        }

        return array_values(array_filter($chunks, fn (string $c): bool => $c !== ''));
    }
}
