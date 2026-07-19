<?php

use App\Models\Call;
use App\Support\TranscriptParser;

/**
 * The transcript is the one artefact both a rep and the client need to read, and
 * it was only ever reachable as a raw textarea on the edit form. These cover the
 * parsing, because a parser that silently drops a caller's words is worse than no
 * view at all.
 */
it('splits a transcript into caller and AI turns', function () {
    $turns = TranscriptParser::parse("CALLER: Hallo, met Jan.\nAI: Goedemiddag Jan, u spreekt met een AI-assistent.");

    expect($turns)->toHaveCount(2)
        ->and($turns[0])->toBe(['speaker' => 'caller', 'text' => 'Hallo, met Jan.'])
        ->and($turns[1]['speaker'])->toBe('ai');
});

it('merges consecutive lines from the same speaker into one turn', function () {
    // The model emits one transcript event per utterance, not per human "turn".
    $turns = TranscriptParser::parse("AI: Goedemiddag.\nAI: Waarmee kan ik u helpen?\nCALLER: Een website.");

    expect($turns)->toHaveCount(2)
        ->and($turns[0]['text'])->toBe("Goedemiddag.\nWaarmee kan ik u helpen?");
});

it('keeps unprefixed continuation lines with the current speaker', function () {
    $turns = TranscriptParser::parse("CALLER: Ik wil graag\neen afspraak maken.");

    expect($turns)->toHaveCount(1)
        ->and($turns[0]['speaker'])->toBe('caller')
        ->and($turns[0]['text'])->toBe("Ik wil graag\neen afspraak maken.");
});

it('keeps text that appears before any speaker prefix rather than dropping it', function () {
    $turns = TranscriptParser::parse("call started\nAI: Goedemiddag.");

    expect($turns)->toHaveCount(2)
        ->and($turns[0]['speaker'])->toBe('system');
});

it('returns nothing for an empty or null transcript', function () {
    expect(TranscriptParser::parse(null))->toBe([])
        ->and(TranscriptParser::parse(''))->toBe([])
        ->and(TranscriptParser::parse("\n  \n"))->toBe([]);
});

it('reads a transcript back through the encrypted column', function () {
    // Guards the seam the view depends on: the cast must round-trip, or the page
    // renders empty for every call.
    $call = Call::create([
        'direction' => 'inbound',
        'status' => 'completed',
        'started_at' => now(),
        'transcript' => "CALLER: Hoi.\nAI: Goedemiddag.",
        'transcript_status' => 'done',
    ]);

    expect(TranscriptParser::parse($call->fresh()->transcript))->toHaveCount(2);
});
