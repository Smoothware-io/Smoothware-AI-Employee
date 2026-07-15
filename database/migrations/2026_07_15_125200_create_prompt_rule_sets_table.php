<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned prompt rulesets. Exactly one set is `active`; that version number is
 * what AI calls record as the ruleset that governed them (ties to
 * `ai_actions.source_context_version`). Publishing changes = a new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status', 20)->default('draft'); // draft|active|archived
            $table->text('notes')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_rule_sets');
    }
};
