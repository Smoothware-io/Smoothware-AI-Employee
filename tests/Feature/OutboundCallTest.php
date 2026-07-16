<?php

use App\Enums\SuppressionType;
use App\Models\Call;
use App\Models\Company;
use App\Models\CompanyAiAnalysis;
use App\Models\KnowledgeEntry;
use App\Services\Outbound\CallInstructionBuilder;
use App\Services\Outbound\OutboundGate;
use App\Services\Outbound\SonetelDialer;
use App\Services\SuppressionList;
use Database\Seeders\SmoothwareKnowledgeSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Outbound calling (Phase 6). The only feature here where a mistake reaches a
 * stranger's phone rather than a colleague's screen — so every gate is asserted
 * to FAIL CLOSED, and the default configuration is asserted to dial nothing.
 */
function configureOutbound(array $overrides = []): void
{
    config(array_merge([
        'outbound.enabled' => true,
        'outbound.disclosure' => 'Je spreekt met een AI-assistent van Smoothware.',
        'outbound.register_screening' => 'none',
        'outbound.allow_without_register_screening' => true,
        'outbound.test_numbers' => [],
        'outbound.max_calls_per_day' => 50,
        'outbound.openai.project_id' => 'proj_test',
        'outbound.openai.key' => 'sk-test',
        'outbound.openai.sip_host' => 'sip.api.openai.com',
        'outbound.sonetel.token' => 'tok_test',
        'outbound.sonetel.caller_id' => '+31201234567',
        'outbound.sonetel.callback_url' => 'https://api.sonetel.com/make-calls/call/call-back',
    ], $overrides));
}

// --- The gates fail closed --------------------------------------------------

it('dials nothing at all with the default configuration', function () {
    // Nothing configured, nothing enabled: the out-of-the-box state must be safe.
    expect(app(OutboundGate::class)->allows('+31612345678'))->toBeFalse();
});

it('refuses when outbound is disabled, whatever else is set', function () {
    configureOutbound(['outbound.enabled' => false]);

    $blockers = app(OutboundGate::class)->blockers('+31612345678');

    expect($blockers)->not->toBeEmpty()
        ->and(implode(' ', $blockers))->toContain('OUTBOUND_ENABLED=false');
});

it('refuses to dial a suppressed number — the absolute one', function () {
    configureOutbound();
    app(SuppressionList::class)->suppress(SuppressionType::Phone, '+31612345678');

    // Art. 21(2): no balancing, no override, not even with everything enabled.
    expect(app(OutboundGate::class)->allows('06 12345678'))->toBeFalse()
        ->and(implode(' ', app(OutboundGate::class)->blockers('0612345678')))
        ->toContain('do-not-contact');
});

it('refuses to dial anyone at a suppressed company', function () {
    configureOutbound();
    app(SuppressionList::class)->suppress(SuppressionType::Domain, 'acme.nl');

    $company = Company::factory()->create(['domain' => 'acme.nl']);

    expect(app(OutboundGate::class)->allows('+31601010101', domain: $company->domain))->toBeFalse();
});

it('refuses without an AI disclosure configured', function () {
    configureOutbound(['outbound.disclosure' => '']);

    // EU AI Act Art. 50 — you must tell a person they are talking to a machine.
    expect(implode(' ', app(OutboundGate::class)->blockers('+31612345678')))
        ->toContain('Art. 50');
});

it('refuses while no opt-out register screening exists', function () {
    configureOutbound(['outbound.allow_without_register_screening' => false]);

    // "none" means unimplemented, NOT "skip". It cannot be satisfied by config.
    expect(implode(' ', app(OutboundGate::class)->blockers('+31612345678')))
        ->toContain('register screening');
});

it('refuses everything outside the test allow-list when one is set', function () {
    configureOutbound(['outbound.test_numbers' => ['+31611111111']]);

    $gate = app(OutboundGate::class);

    // The safest way to test a system that makes phone calls: it cannot reach a
    // stranger by mistake. Normalisation applies, so 06… matches +316….
    expect($gate->allows('+31611111111'))->toBeTrue()
        ->and($gate->allows('0611111111'))->toBeTrue()
        ->and($gate->allows('+31699999999'))->toBeFalse();
});

it('refuses once the daily cap is reached', function () {
    configureOutbound(['outbound.max_calls_per_day' => 2]);

    Call::factory()->count(2)->create(['direction' => 'outbound']);

    expect(implode(' ', app(OutboundGate::class)->blockers('+31612345678')))
        ->toContain('Daily outbound cap');
});

it('refuses when the provider config is incomplete', function () {
    configureOutbound(['outbound.sonetel.token' => '']);

    expect(implode(' ', app(OutboundGate::class)->blockers('+31612345678')))
        ->toContain('sonetel.token');
});

// --- The dialler ------------------------------------------------------------

it('throws loudly rather than silently skipping a blocked call', function () {
    configureOutbound();
    app(SuppressionList::class)->suppress(SuppressionType::Phone, '+31612345678');
    Http::fake();

    // A silent no-op looks like a bug and gets "fixed" by deleting the check.
    expect(fn () => app(SonetelDialer::class)->call('+31612345678'))
        ->toThrow(RuntimeException::class, 'Refusing to dial');

    Http::assertNothingSent();
});

it('bridges the OpenAI SIP leg to the prospect via Sonetel', function () {
    configureOutbound();
    Http::fake(['api.sonetel.com/*' => Http::response(['call_id' => 'son_123'])]);

    $company = Company::factory()->create(['name' => 'Acme BV']);
    $call = app(SonetelDialer::class)->call('+31612345678', $company);

    Http::assertSent(function ($request) {
        // Leg 1 is the AI, leg 2 is the person. OpenAI cannot dial — it can only
        // receive SIP — so Sonetel bridges the two.
        return $request['call1'] === 'sip:proj_test@sip.api.openai.com;transport=tls'
            && $request['call2'] === '+31612345678'
            && $request['show2'] === '+31201234567';
    });

    expect($call->direction->value)->toBe('outbound')
        ->and($call->status->value)->toBe('dialing')
        ->and($call->external_id)->toBe('son_123')
        ->and($call->company_id)->toBe($company->id);
});

it('marks the call failed when Sonetel refuses', function () {
    configureOutbound();
    Http::fake(['api.sonetel.com/*' => Http::response(['error' => 'nope'], 402)]);

    expect(fn () => app(SonetelDialer::class)->call('+31612345678'))
        ->toThrow(RuntimeException::class, 'Sonetel refused');

    expect(Call::latest('id')->first()->status->value)->toBe('failed');
});

// --- What the AI is actually told -------------------------------------------

it('puts the AI disclosure first, before anything else', function () {
    configureOutbound();

    $built = app(CallInstructionBuilder::class)->forCompany(null);

    // Art. 50 is not a rule the model weighs against other rules.
    expect($built['instructions'])->toStartWith('OPENINGSREGEL')
        ->and($built['instructions'])->toContain('AI-assistent van Smoothware');
});

it('grounds the call in the knowledge base and stamps the context version', function () {
    configureOutbound();
    $this->seed(SmoothwareKnowledgeSeeder::class);

    // The seeder writes drafts; publishing is what makes them retrievable.
    KnowledgeEntry::query()->update(['status' => 'published']);
    KnowledgeEntry::all()->each->touch(); // re-embed via the model hook

    $built = app(CallInstructionBuilder::class)->forCompany(null, 'websites verkopen');

    expect($built['instructions'])->toContain('KENNISBANK')
        ->and($built['context_version'])->toContain('rules:v1');
});

it('tells the AI it knows nothing when the knowledge base is empty', function () {
    configureOutbound();

    $built = app(CallInstructionBuilder::class)->forCompany(null);

    // No KB, no undo, no review queue — so it must say so rather than improvise.
    expect($built['instructions'])->toContain('GEEN')
        ->and($built['instructions'])->toContain('draag het gesprek over');
});

it('always includes the hard limits, KB or not', function () {
    configureOutbound();

    $built = app(CallInstructionBuilder::class)->forCompany(null);

    expect($built['instructions'])->toContain('Verzin niets')
        ->and($built['instructions'])->toContain('Noem NOOIT een prijs')
        // Honouring an objection outranks every sales objective.
        ->and($built['instructions'])->toContain('niet meer gebeld');
});

it('gives the AI what we measured about the company it is calling', function () {
    configureOutbound();

    $company = Company::factory()->create(['name' => 'Acme BV', 'city' => 'Utrecht', 'industry' => 'Retail']);
    CompanyAiAnalysis::factory()->for($company)->create([
        'technical' => [['key' => 'pagespeed', 'label' => 'PageSpeed', 'assessment' => 'Score 42/100', 'confidence' => 0.9]],
    ]);

    $built = app(CallInstructionBuilder::class)->forCompany($company);

    expect($built['instructions'])->toContain('Acme BV')
        ->and($built['instructions'])->toContain('Utrecht')
        // A real measured defect is what makes the call informed, not a script.
        ->and($built['instructions'])->toContain('Score 42/100');
});
