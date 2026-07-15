<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual behavioural rules belonging to a ruleset version, e.g.
 * "never promise a fixed price", "always offer a meeting for custom software".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_rule_set_id')->constrained('prompt_rule_sets')->cascadeOnDelete();
            $table->string('category')->nullable(); // e.g. pricing, meetings, honesty
            $table->text('rule_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('prompt_rule_set_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_rules');
    }
};
