<?php

namespace App\Support;

/**
 * Turns the stored transcript into speaker turns the UI can render.
 *
 * The transcript is written as flat lines prefixed `CALLER: ` or `AI: ` (see
 * ObserveRealtimeCall::transcriptLine and go-voice's session.go). That format is
 * deliberately dumb — it survives encryption, truncation and a human reading the
 * raw column — but it is unreadable as a conversation, which is what a rep
 * reviewing a call actually needs.
 *
 * Parsing is forgiving on purpose: a line with no prefix belongs to whoever spoke
 * last (speech recognition emits newlines mid-sentence), and anything before the
 * first prefix is kept rather than dropped. Losing a caller's words to a parser
 * being strict about its own format would be the worst possible trade.
 */
class TranscriptParser
{
    /**
     * @return array<int, array{speaker: string, text: string}>
     */
    public static function parse(?string $transcript): array
    {
        if (! filled($transcript)) {
            return [];
        }

        $turns = [];

        foreach (preg_split('/\R/', $transcript) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(CALLER|AI|SYSTEM)\s*:\s*(.*)$/i', $line, $m) === 1) {
                $speaker = strtolower($m[1]);
                $text = trim($m[2]);

                // Consecutive lines from one speaker are one turn: the model emits
                // a transcript event per utterance, not per thing a person "said".
                $last = array_key_last($turns);
                if ($last !== null && $turns[$last]['speaker'] === $speaker) {
                    $turns[$last]['text'] = trim($turns[$last]['text']."\n".$text);

                    continue;
                }

                $turns[] = ['speaker' => $speaker, 'text' => $text];

                continue;
            }

            // No prefix: a continuation of the current turn, or an unlabelled
            // preamble if nothing has been said yet.
            $last = array_key_last($turns);
            if ($last === null) {
                $turns[] = ['speaker' => 'system', 'text' => $line];

                continue;
            }

            $turns[$last]['text'] = trim($turns[$last]['text']."\n".$line);
        }

        return array_values(array_filter($turns, fn (array $t): bool => $t['text'] !== ''));
    }
}
