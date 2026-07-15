<?php

use App\Contracts\ReceptionistLlm;
use App\Enums\CallIntent;
use App\Models\AiAction;
use App\Models\Call;
use App\Models\Company;
use App\Models\KnowledgeEntry;
use App\Services\Receptionist\ReceptionistPipeline;
use App\Support\Receptionist\ReceptionistAnalysis;

function inboundCall(string $transcript): Call
{
    return Call::factory()->create([
        'company_id' => null,
        'from_number' => '+31612345678',
        'transcript' => $transcript,
    ]);
}

it('produces a grounded analysis and a draft when the KB supports the call', function () {
    KnowledgeEntry::factory()->published()->create([
        'title' => 'Websites and SEO',
        'body' => 'We build modern websites and improve SEO search ranking for clients.',
    ]);
    $call = inboundCall('Hi, I run a shop and want a new website and better SEO ranking.');

    $run = app(ReceptionistPipeline::class)->process($call, $call->transcript);

    expect($run->grounded)->toBeTrue()
        ->and($run->fallback_to_human)->toBeFalse()
        ->and($run->kind)->toBe('receptionist')
        ->and($run->context_version)->toContain('kb:')
        ->and($call->fresh()->intent)->toBe(CallIntent::SalesInquiry);

    // A draft was proposed for review — nothing was auto-created.
    $draft = AiAction::where('action_type', 'receptionist_intake')->pendingReview()->first();
    expect($draft)->not->toBeNull()
        ->and($draft->ai_run_id)->toBe($run->uuid)
        ->and($draft->source_context_version)->toBe($run->context_version)
        ->and(Company::count())->toBe(0);
});

it('falls back to human when nothing grounds the call, and never improvises', function () {
    // No published KB → retrieval is empty.
    $call = inboundCall('Do you offer quantum blockchain consulting for spaceships?');

    $run = app(ReceptionistPipeline::class)->process($call, $call->transcript);

    expect($run->grounded)->toBeFalse()
        ->and($run->fallback_to_human)->toBeTrue();

    $draft = AiAction::where('action_type', 'receptionist_intake')->pendingReview()->firstOrFail();
    expect($draft->proposed_payload['grounded'])->toBeFalse()
        ->and($draft->proposed_payload['task']['title'])->toContain('Human follow-up');
});

it('rejects grounding when the model cites a chunk it was not given', function () {
    KnowledgeEntry::factory()->published()->create([
        'title' => 'Hosting',
        'body' => 'We provide managed hosting and maintenance.',
    ]);
    $call = inboundCall('I need hosting and maintenance for my site.');

    // Stub LLM that fabricates a citation to a chunk id it was never given.
    app()->instance(ReceptionistLlm::class, new class implements ReceptionistLlm
    {
        public function analyze(string $transcript, array $chunks, array $rules): ReceptionistAnalysis
        {
            return new ReceptionistAnalysis(
                intent: CallIntent::SalesInquiry,
                summary: 'Caller wants hosting.',
                answer: 'Yes, we do that.',      // claims an answer...
                usedChunkIds: [999999],           // ...citing a chunk that does not exist
                confidence: 0.9,
            );
        }
    });

    $run = app(ReceptionistPipeline::class)->process($call, $call->transcript);

    expect($run->grounded)->toBeFalse()          // invalid citation => not grounded
        ->and($run->fallback_to_human)->toBeTrue();
});
