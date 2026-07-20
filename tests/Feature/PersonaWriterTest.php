<?php

use App\Enums\CallDirection;
use App\Enums\PersonaPreset;
use App\Services\Voice\PersonaWriter;
use Illuminate\Support\Facades\Http;

/**
 * The generator DRAFTS. It never adopts its own output, and it cannot widen what
 * the AI is allowed to do — the disclosure, the limits and the tool rules are
 * appended after it by CallInstructionBuilder.
 */
function fakeDraft(array $payload = ['role' => 'Je neemt de telefoon op.', 'goal' => 'Plan een gesprek in.']): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
        ]),
    ]);
}

it('is unavailable when no AI provider is connected', function () {
    config(['services.anthropic.key' => null]);

    expect(app(PersonaWriter::class)->configured())->toBeFalse();

    app(PersonaWriter::class)->draft(PersonaPreset::Sales, CallDirection::Outbound);
})->throws(RuntimeException::class, 'No AI provider is connected');

it('returns a role and a goal from the preset', function () {
    config(['services.anthropic.key' => 'test-key']);
    fakeDraft();

    $draft = app(PersonaWriter::class)->draft(PersonaPreset::Sales, CallDirection::Outbound);

    expect($draft['role'])->toBe('Je neemt de telefoon op.')
        ->and($draft['goal'])->toBe('Plan een gesprek in.');
});

it('tells the writer which direction the call goes', function () {
    // An AI that answers must not announce a call; one that calls must not ask
    // "how can I help you?". The brief has to carry that or the draft is wrong.
    config(['services.anthropic.key' => 'test-key']);
    fakeDraft();

    app(PersonaWriter::class)->draft(PersonaPreset::Reception, CallDirection::Inbound);

    Http::assertSent(function ($request): bool {
        $content = $request['messages'][0]['content'];

        return str_contains($content, 'INBOUND') && str_contains($content, 'rang US');
    });
});

it('forbids the writer from restating the safety rules', function () {
    // Two versions of the disclosure wording is the one thing that must never
    // exist; the generated text must not contain a second copy.
    config(['services.anthropic.key' => 'test-key']);
    fakeDraft();

    app(PersonaWriter::class)->draft(PersonaPreset::Sales, CallDirection::Outbound);

    Http::assertSent(fn ($request): bool => str_contains(
        $request['system'],
        'Do NOT restate legal disclosures, safety limits or tool instructions',
    ));
});

it('fails loudly rather than returning junk the model did not shape', function () {
    config(['services.anthropic.key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'sorry, I cannot do that']],
        ]),
    ]);

    app(PersonaWriter::class)->draft(PersonaPreset::Sales, CallDirection::Outbound);
})->throws(RuntimeException::class, 'did not return usable instructions');

it('gives every preset a goal and a brief', function () {
    // A preset with no brief silently produces a generic persona.
    foreach (PersonaPreset::cases() as $preset) {
        expect($preset->goal())->not->toBeEmpty()
            ->and($preset->brief())->not->toBeEmpty()
            ->and($preset->getLabel())->not->toBeEmpty();
    }
});
