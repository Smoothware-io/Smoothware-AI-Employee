<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staged CSV rows (Phase 5). Each parsed row is deduped + validated and given a
 * disposition (create/match/skip/invalid) so the preview shows exactly what will
 * happen before anyone commits. On commit, `company_id` records the created or
 * matched company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->jsonb('raw');                 // original CSV row (header => value)
            $table->jsonb('mapped');              // mapped fields (name, domain, ...)
            $table->string('disposition', 20);    // create|match|skip|invalid
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->jsonb('errors')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
