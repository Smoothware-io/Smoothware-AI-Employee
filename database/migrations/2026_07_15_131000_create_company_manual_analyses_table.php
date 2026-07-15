<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human-owned company analysis (Phase 4). One per company. AI code NEVER writes
 * here — this is the rep's judgment, kept physically separate from the AI's
 * (product principle #2). Editable inline on the company; AI analysis lives in
 * its own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_manual_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->text('pain_points')->nullable();
            $table->text('opportunities')->nullable();
            $table->text('notes')->nullable();
            $table->string('priority', 10)->nullable(); // high|medium|low

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_manual_analyses');
    }
};
