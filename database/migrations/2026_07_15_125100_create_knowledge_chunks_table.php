<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived chunks of a knowledge entry, each with its embedding vector. Chunks
 * are regenerated whenever the entry changes (async job), so no soft delete —
 * they cascade with the entry.
 *
 * The embedding is stored as a jsonb array of floats. Postgres pgvector is
 * available but deferred; a single agency's KB is small enough that brute-force
 * cosine similarity in PHP is sufficient for now (see ARCHITECTURE §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('token_count')->nullable();

            $table->jsonb('embedding')->nullable();        // array<float>
            $table->string('embedding_model')->nullable(); // e.g. 'voyage-3'
            $table->unsignedSmallInteger('embedding_dims')->nullable();
            $table->timestamp('embedded_at')->nullable();

            $table->timestamps();

            $table->index('knowledge_entry_id');
            $table->index('embedded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
