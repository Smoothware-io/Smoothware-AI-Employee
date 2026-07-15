<?php

use App\Enums\PromptRuleSetStatus;
use App\Models\Event;
use App\Models\KnowledgeEntry;
use App\Models\PromptRuleSet;
use App\Services\ContextVersion;
use App\Services\PromptRuleSetService;

beforeEach(function () {
    $this->service = app(PromptRuleSetService::class);
});

it('activates a set and archives the previously active one', function () {
    $v1 = PromptRuleSet::factory()->create(['version' => 1]);
    $this->service->activate($v1);
    expect($v1->fresh()->status)->toBe(PromptRuleSetStatus::Active);

    $v2 = PromptRuleSet::factory()->create(['version' => 2]);
    $this->service->activate($v2);

    expect($v2->fresh()->status)->toBe(PromptRuleSetStatus::Active)
        ->and($v1->fresh()->status)->toBe(PromptRuleSetStatus::Archived)
        ->and($this->service->active()->id)->toBe($v2->id);
});

it('keeps at most one active set', function () {
    $this->service->activate(PromptRuleSet::factory()->create(['version' => 1]));
    $this->service->activate(PromptRuleSet::factory()->create(['version' => 2]));

    expect(PromptRuleSet::active()->count())->toBe(1);
});

it('computes the next version number', function () {
    PromptRuleSet::factory()->create(['version' => 3]);

    expect($this->service->nextVersion())->toBe(4);
});

it('logs activation to the append-only event log', function () {
    $this->service->activate(PromptRuleSet::factory()->create(['version' => 1]));

    expect(Event::where('action', 'prompt_rule_set.activated')->exists())->toBeTrue();
});

it('stamps the context version with the active ruleset and KB state', function () {
    $this->service->activate(PromptRuleSet::factory()->create(['version' => 7]));
    KnowledgeEntry::factory()->published()->create(['body' => 'hosting services']);

    $version = app(ContextVersion::class)->current();

    expect($version)->toContain('rules:v7')
        ->and($version)->toContain('kb:');
});

it('reports rules:none and kb:empty when nothing is configured', function () {
    expect(app(ContextVersion::class)->current())->toBe('rules:none|kb:empty');
});
