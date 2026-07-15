<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base — one flexible table for every content type (company info,
 * services, FAQ, pricing guidelines, processes, portfolio). This is the RAG
 * source of truth: only `published` entries are chunked, embedded and retrieved.
 * `last_verified_at` guards against stale info reaching a live call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);              // KnowledgeType
            $table->string('title');
            $table->longText('body')->nullable();    // the text that gets embedded
            $table->jsonb('data')->nullable();        // type-specific structure (pricing factors, portfolio meta, ...)
            $table->string('status', 20)->default('draft'); // draft|published|archived

            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 20)->default('manual');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index('type');
            $table->index('status');
            $table->index('last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
    }
};
