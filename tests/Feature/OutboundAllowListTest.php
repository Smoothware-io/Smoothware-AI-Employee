<?php

use App\Enums\SuppressionType;
use App\Services\Outbound\OutboundGate;
use App\Services\SuppressionList;

/**
 * The allow-list fails CLOSED.
 *
 * It used to read the other way: an empty OUTBOUND_TEST_NUMBERS meant "list not
 * in use", so the one mistake nobody would notice — enabling outbound and
 * forgetting the test list — silently pointed the dialler at every number in the
 * database. Every other gate here treats a missing answer as "no"; this one now
 * agrees with them.
 */
beforeEach(function () {
    // Clear the unrelated gates so these assertions are about the allow-list.
    config([
        'outbound.enabled' => true,
        'outbound.register_screening' => 'tps',
        'outbound.openai.project_id' => 'proj_test',
        'outbound.openai.key' => 'sk-test',
        'outbound.test_numbers' => [],
        'outbound.allow_any_number' => false,
    ]);
});

it('dials nobody when no test numbers are set', function () {
    expect(app(OutboundGate::class)->allows('+31612345678'))->toBeFalse();
});

it('says plainly that nothing may be dialled, and how to change it', function () {
    // A refusal with no reason is how people start disabling safety checks.
    $blockers = app(OutboundGate::class)->blockers('+31612345678');

    expect(implode(' ', $blockers))
        ->toContain('OUTBOUND_TEST_NUMBERS')
        ->toContain('OUTBOUND_ALLOW_ANY_NUMBER');
});

it('dials a number that is on the test list', function () {
    config(['outbound.test_numbers' => ['+31612345678']]);

    expect(app(OutboundGate::class)->allows('+31612345678'))->toBeTrue();
});

it('still refuses a number that is not on the test list', function () {
    config(['outbound.test_numbers' => ['+31612345678']]);

    expect(app(OutboundGate::class)->allows('+31699999999'))->toBeFalse();
});

it('dials any number only once that is explicitly switched on', function () {
    config(['outbound.allow_any_number' => true]);

    expect(app(OutboundGate::class)->allows('+31699999999'))->toBeTrue();
});

it('lets the test list win over allow-any, so the dialler cannot be half-opened', function () {
    // Both set: the narrower rule applies. Otherwise a leftover ALLOW_ANY from
    // production would silently widen a test run back to the whole database.
    config([
        'outbound.test_numbers' => ['+31612345678'],
        'outbound.allow_any_number' => true,
    ]);

    expect(app(OutboundGate::class)->allows('+31612345678'))->toBeTrue()
        ->and(app(OutboundGate::class)->allows('+31699999999'))->toBeFalse();
});

it('never lets allow-any override the do-not-contact list', function () {
    // Art. 21(2) is absolute: no switch in this file may outrank it.
    config(['outbound.allow_any_number' => true]);

    // Through the service, so the value is normalised exactly the way the gate
    // looks it up. A hand-inserted row can be "suppressed" and still get dialled.
    app(SuppressionList::class)->suppress(SuppressionType::Phone, '+31699999999');

    expect(app(OutboundGate::class)->allows('+31699999999'))->toBeFalse();
});
