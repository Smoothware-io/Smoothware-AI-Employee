<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI was asked to achieve on an outbound call (Phase 6).
 *
 * Stored per call rather than only on the campaign, because the objective is
 * part of the provenance: a recording is only readable against the intent it was
 * placed with. "Why did the AI say that?" needs both the KB version and the goal
 * it was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->text('objective')->nullable()->after('intent');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('objective');
        });
    }
};
