<?php

use App\Enums\CallDirection;
use App\Services\Outbound\CallInstructionBuilder;

/**
 * An AI that ANSWERS must not announce a call, and an AI that CALLS must not ask
 * "how can I help you?".
 *
 * Both bugs were live until a real call was minutes away: the instructions were
 * written outbound-first, so answering the phone would have opened with "I am
 * calling on behalf of Smoothware — is now convenient?" to someone who had just
 * dialled us.
 */
it('answers the phone as a receptionist, not as a cold caller', function () {
    $built = app(CallInstructionBuilder::class)->forCompany(null, null, CallDirection::Inbound);

    expect($built['instructions'])
        ->toContain('NEEMT DE TELEFOON OP')
        ->toContain('Deze persoon belt ONS')
        // The outbound opener asks whether now is convenient — absurd inbound.
        ->not->toContain('Schikt het u om even te praten');
});

it('calls out as a caller, not as a receptionist', function () {
    $built = app(CallInstructionBuilder::class)->forCompany(null, null, CallDirection::Outbound);

    expect($built['instructions'])
        ->toContain('Je BELT namens Smoothware')
        ->toContain('Schikt het u om even te praten')
        ->not->toContain('Waarmee kan ik u helpen');
});

it('always speaks a real AI disclosure, whichever way the call goes', function (CallDirection $direction) {
    // This was NULL for inbound — a mis-nested config key left the mandatory
    // Art. 50 line blank, so the AI would have answered with no disclosure at
    // all. A typo silently defeating a legal control.
    $built = app(CallInstructionBuilder::class)->forCompany(null, null, $direction);

    expect($built['instructions'])
        ->toStartWith('OPENINGSREGEL')
        ->toContain('AI-assistent van Smoothware')
        // Never claim recording we have not proven.
        ->toContain('kan worden opgenomen')
        ->not->toContain('wordt opgenomen');
})->with([CallDirection::Inbound, CallDirection::Outbound]);

it('never promises recording as a certainty', function () {
    // The disclosure is spoken in the same breath as "I am an AI". If the second
    // half is false, the first half is worthless.
    expect(config('receptionist.ai_disclosure'))->toContain('kan worden opgenomen')
        ->and(config('outbound.disclosure'))->toContain('kan worden opgenomen');
});
