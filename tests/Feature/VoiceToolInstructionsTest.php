<?php

use App\Enums\CallDirection;
use App\Services\Outbound\CallInstructionBuilder;

/**
 * The instructions and the tool declarations must AGREE.
 *
 * On the first real call the AI said "I can't book meetings directly" and pointed
 * at the website — while `book_appointment` was declared and ready. It was obeying
 * the prose, which still said it had no hands. Declaring a tool is not enough; the
 * model believes what it is told in words.
 */
it('tells the AI it can book when tools are available', function () {
    $built = app(CallInstructionBuilder::class)->forCompany(
        company: null,
        direction: CallDirection::Inbound,
        withTools: true,
    );

    expect($built['instructions'])
        ->toContain('get_available_times')
        ->toContain('book_appointment')
        ->toContain('add_note')
        // The explicit correction of the behaviour we saw on the real call.
        ->toContain('Zeg NOOIT dat je dat niet kunt');
});

it('never mentions tools when the gateway cannot execute them', function () {
    $built = app(CallInstructionBuilder::class)->forCompany(
        company: null,
        direction: CallDirection::Inbound,
        withTools: false,
    );

    // Promising a capability nothing can carry out is a worse lie than declining.
    expect($built['instructions'])
        ->not->toContain('book_appointment')
        ->not->toContain('get_available_times');
});

it('still forbids inventing availability, tools or not', function () {
    $withTools = app(CallInstructionBuilder::class)
        ->forCompany(company: null, direction: CallDirection::Inbound, withTools: true)['instructions'];

    // The guardrail is unchanged in substance: availability may only come from
    // the calendar. What changed is that there is now a way to ask it.
    expect($withTools)->toContain('Verzin nooit zelf');
});
