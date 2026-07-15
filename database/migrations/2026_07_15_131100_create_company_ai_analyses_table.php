<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Machine-generated company analysis (Phase 4). Regenerable — each generation is
 * a new row (history), the latest is "current". Findings are JSON with a
 * per-finding confidence. Carries full provenance (source_context_version,
 * model_id, ai_run_id) so any finding traces to the model + KB state that
 * produced it. Never touches the manual analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Finding groups: [{key,label,assessment,confidence}, ...]
            $table->jsonb('technical')->nullable();       // PageSpeed, SEO, mobile, SSL, CMS, analytics, tracking
            $table->jsonb('marketing')->nullable();       // CTA, branding, conversion, social media
            $table->jsonb('recommendations')->nullable(); // website, SEO, AI chatbot, hosting

            $table->string('inferred_priority', 10)->nullable(); // AI's own priority read (vs manual)
            $table->decimal('overall_confidence', 4, 3)->nullable();

            $table->string('source_context_version')->nullable();
            $table->string('model_id')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->string('source', 20)->default('ai');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index(['company_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_ai_analyses');
    }
};
