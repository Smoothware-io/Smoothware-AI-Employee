<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The follow-up ledger (Phase 7): one row per automation DECISION — including
 * decisions not to act (skipped/failed are kept, so "why didn't it fire?" is
 * answerable).
 *
 * Two columns carry the design:
 *
 *  - `dedup_key` (UNIQUE) — the evaluator re-runs over the same events and the
 *    same quiet companies every day. Idempotency is enforced by the DATABASE
 *    rather than by the code remembering. Phase 5's committer guard taught the
 *    same lesson; here the guard is a constraint, which cannot be forgotten.
 *
 *  - `rule_snapshot` — the rule as it read WHEN IT FIRED. Without it, editing a
 *    rule silently rewrites history: the ledger says "rule 3 fired" while rule 3
 *    now says something else. Cheaper than versioning rules (cf. prompt_rule_sets)
 *    and sufficient, because nothing ever re-runs an old rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();

            // Null for AI-suggested follow-ups (path deferred — rules only at launch).
            $table->foreignId('follow_up_rule_id')->nullable()->constrained('follow_up_rules')->nullOnDelete();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('trigger', 40);
            // The exact event that fired this — the audit link back to the timeline.
            $table->foreignId('trigger_event_id')->nullable()->constrained('events')->nullOnDelete();

            $table->jsonb('rule_snapshot')->nullable();
            $table->text('reason')->nullable();     // human-readable "why this fired"

            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->string('status', 20)->default('applied'); // App\Enums\FollowUpStatus
            $table->timestamp('due_at')->nullable();

            // Provenance (Phase 0 convention). Rule-driven rows are source=system;
            // the AI columns stay null until the suggestion path is enabled.
            $table->string('source', 20)->default('system');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->string('source_context_version')->nullable();
            $table->string('model_id')->nullable();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();

            $table->string('dedup_key')->unique();

            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
