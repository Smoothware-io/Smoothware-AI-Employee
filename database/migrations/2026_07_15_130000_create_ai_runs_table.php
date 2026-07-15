<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AI invocation (receptionist analysis now; company analysis /
 * outbound later). Captures the grounding verdict and the ops metrics —
 * latency, tokens, cost — that feed the Phase 8 AI-ops dashboard.
 *
 * `uuid` is what `ai_actions.ai_run_id` (a uuid, from Phase 0) references, so the
 * two link without altering the Phase 0 schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind'); // receptionist | analysis | outbound
            $table->string('model_id')->nullable();
            $table->string('context_version')->nullable();

            // What the run was about (loose polymorphic ref, e.g. a Call).
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->boolean('grounded')->default(false);
            $table->boolean('fallback_to_human')->default(false);
            $table->jsonb('retrieved_chunk_ids')->nullable();

            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 5)->nullable();
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->index('kind');
            $table->index(['subject_type', 'subject_id']);
            $table->index('fallback_to_human');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
