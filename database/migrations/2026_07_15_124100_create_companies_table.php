<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Contact/identity fields (domain + phone also power the Phase 3/5
            // dedup matcher — hence the indexes below).
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->default('NL');
            $table->string('industry')->nullable();

            $table->string('status', 20)->default('lead');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // Provenance (human vs AI vs import).
            $table->string('source', 20)->default('manual');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index('status');
            $table->index('owner_id');
            $table->index('domain');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
