<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable "timeline anchor" to the append-only event log. Company-scoped
 * events (a note added, a task completed, a call logged on company X) set this
 * at write time so the Company Timeline (Phase 1) is a single indexed query
 * instead of an aggregate across every child table.
 *
 * Still append-only: the anchor is written once and never mutated. No FK — the
 * company may later be archived/erased while its history must survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('entity_id');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropColumn('company_id');
        });
    }
};
