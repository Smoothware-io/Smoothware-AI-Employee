<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI action framework (Phase 0) — reusable "AI proposes -> human approves /
 * rejects -> action executes" infrastructure. Phases 3 (receptionist), 4
 * (analysis) and 6 (outbound) ALL flow through this one table rather than each
 * reinventing an approval mechanism.
 *
 * Every AI-originated record in the system traces back to a row here, carrying
 * the confidence score, the knowledge-base/prompt version used, and the model
 * that produced it — satisfying the auditability principle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_actions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // What the AI wants to do, e.g. 'create_company', 'company_analysis'.
            $table->string('action_type');

            // Lifecycle: draft -> approved|rejected ; approved/auto -> applied.
            $table->string('status', 20)->default('draft'); // draft|approved|rejected|auto_applied

            // The proposed change (what would be created/updated on approval).
            $table->jsonb('proposed_payload');

            // The record this action targets/produces (loose polymorphic ref).
            // Null until known (e.g. a brand-new company created on apply).
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            // Auditability trio.
            $table->decimal('confidence_score', 4, 3)->nullable(); // 0.000 - 1.000
            $table->string('source_context_version')->nullable();  // KB + prompt-ruleset version
            $table->string('model_id')->nullable();                // e.g. claude-opus-4-8

            // Traceability back to the originating AI run/conversation.
            $table->uuid('ai_run_id')->nullable();

            // Human-in-the-loop review trail.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();
            $table->softDeletes('archived_at');

            // The review queue polls "pending drafts, newest first" (Q from user
            // about Phase 3 near-real-time review) — keep that cheap.
            $table->index(['status', 'created_at']);
            $table->index('action_type');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_actions');
    }
};
