<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CSV import run (Phase 5). Uploaded → staged (parsed + deduped into
 * import_rows for the preview) → committed. Defaults (owner/status/campaign/
 * industry) are applied to every created company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('status', 20)->default('pending');
            $table->jsonb('column_mapping')->nullable(); // field => csv header

            // Defaults applied to created companies.
            $table->foreignId('default_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('default_status', 20)->nullable();
            $table->string('default_industry')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();

            $table->unsignedInteger('create_count')->default(0);
            $table->unsignedInteger('match_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->text('error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
