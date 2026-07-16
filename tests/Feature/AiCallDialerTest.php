<?php

use App\Contracts\CallOriginator;
use App\Enums\SuppressionType;
use App\Models\Call;
use App\Models\Company;
use App\Models\SonetelAccount;
use App\Models\User;
use App\Services\Outbound\AiCallDialer;
use App\Services\Outbound\FakeCallOriginator;
use App\Services\SuppressionList;

/**
 * The dialler is the only thing in this system that can reach a stranger's phone.
 * These tests exist to prove it refuses to, far more than to prove it works.
 */
beforeEach(function () {
    $this->fake = new FakeCallOriginator;
    $this->app->instance(CallOriginator::class, $this->fake);

    $this->user = User::factory()->create();

    SonetelAccount::create([
        'user_id' => $this->user->getKey(),
        'username' => 'joshia@smoothware.io',
        'sonetel_number' => '+3197010279813',
        // Encrypted at rest by the model's casts. The password is never stored —
        // it is exchanged once for this and discarded.
        'access_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
});

function dialer(): AiCallDialer
{
    return app(AiCallDialer::class);
}

/**
 * Open every gate, so each test can close exactly the one it is about.
 *
 * That this needs six keys is the point: the default refuses, and reaching a
 * phone takes six deliberate decisions.
 */
function gatesOpen(array $testNumbers = ['+31612345678']): void
{
    config([
        'outbound.enabled' => true,
        'outbound.register_screening' => 'implemented',
        'outbound.test_numbers' => $testNumbers,
        'outbound.disclosure' => 'Je spreekt met een AI-assistent van Smoothware.',
        'outbound.openai.project_id' => 'proj_test',
        'outbound.openai.key' => 'sk-test',
    ]);
}

it('dials nothing at all with the default configuration', function () {
    // The most important test in this file. Every gate fails closed, so a fresh
    // checkout with no config cannot place a call. If this ever passes by
    // accident, the safe default has stopped being safe.
    expect(fn () => dialer()->call('+31612345678', as: $this->user))
        ->toThrow(RuntimeException::class, 'Refusing to dial');

    expect($this->fake->originated)->toBeEmpty();
});

it('refuses a suppressed number even when every other gate is open', function () {
    gatesOpen();

    app(SuppressionList::class)->suppress(
        SuppressionType::Phone,
        '+31612345678',
        reason: 'asked us to stop',
    );

    expect(fn () => dialer()->call('+31612345678', as: $this->user))
        ->toThrow(RuntimeException::class);

    // Suppression is absolute: it is the promise we made to a person who told us
    // to stop. No configuration may override it, which is why this asserts on the
    // originator and not only on the exception.
    expect($this->fake->originated)->toBeEmpty();
});

it('originates through Asterisk once every gate is satisfied', function () {
    gatesOpen();

    $company = Company::factory()->create();

    $call = dialer()->call('+31612345678', company: $company, objective: 'intro', as: $this->user);

    expect($this->fake->originated)->toHaveCount(1)
        ->and($this->fake->lastPhone())->toBe('+31612345678')
        // The prospect sees the rep's own number: the person accountable for the
        // call is the person whose phone rings back.
        ->and($this->fake->originated[0]['caller_id'])->toBe('+3197010279813');

    expect($call->company_id)->toBe($company->getKey())
        ->and($call->handled_by)->toBe($this->user->getKey());
});

it('records the call as dialing, not in_progress', function () {
    gatesOpen();

    $call = dialer()->call('+31612345678', as: $this->user);

    // Regression: SonetelDialer reported a queued 202 as "Calling…" and the rep
    // believed a call was happening. Asterisk has accepted a request to make a
    // phone ring. Nobody has answered. The AI has not spoken.
    expect($call->status->value)->toBe('dialing');
});

it('marks the call failed when Asterisk refuses, rather than leaving it dialing forever', function () {
    gatesOpen();

    $this->fake->shouldFail = true;

    expect(fn () => dialer()->call('+31612345678', as: $this->user))
        ->toThrow(RuntimeException::class);

    // A row stuck in 'dialing' is worse than a failure: reporting counts it as a
    // live call and the rep has no idea it never happened.
    expect(Call::latest('id')->first()?->status->value)->toBe('failed');
});

it('refuses to dial when the rep has no Sonetel number to call from', function () {
    gatesOpen();

    SonetelAccount::query()->delete();

    // A withheld caller ID on a sales call is hostile, and for telemarketing it is
    // unlawful. Refuse rather than dial anonymously.
    expect(fn () => dialer()->call('+31612345678', as: $this->user))
        ->toThrow(RuntimeException::class, 'no Sonetel number');

    expect($this->fake->originated)->toBeEmpty();
});

it('refuses to dial a number outside the test allow-list', function () {
    gatesOpen(['+31612345678']);

    // The allow-list means ONLY these numbers. That is what makes it safe to
    // exercise a live dialler without being able to reach a stranger by mistake.
    expect(fn () => dialer()->call('+31699999999', as: $this->user))
        ->toThrow(RuntimeException::class);

    expect($this->fake->originated)->toBeEmpty();
});

it('binds the fake originator by default so a test can never reach a real PBX', function () {
    // Resolve fresh from the container rather than the instance() above — this is
    // asserting on the BINDING, which is what phpunit.xml pins. If it fails, the
    // suite has started inheriting .env again, which is how a test run could dial
    // a real person. That has happened twice.
    $this->app->forgetInstance(CallOriginator::class);

    expect(app(CallOriginator::class))->toBeInstanceOf(FakeCallOriginator::class);
});
