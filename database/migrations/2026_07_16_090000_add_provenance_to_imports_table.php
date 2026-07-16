<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import provenance (GO-LIVE-LEGAL.md item #2). We already recorded THAT a
 * company was imported; this records WHERE the list came from and under WHICH
 * lawful basis — the two things a regulator actually asks about data we did not
 * collect from the data subject.
 *
 * Nullable on purpose: pre-existing imports genuinely have no answer, and
 * backfilling a default would fabricate a lawful basis, which is the exact
 * failure this column exists to prevent. The requirement is enforced at the form
 * layer, where a human can actually answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->text('list_source')->nullable()->after('original_name');
            $table->string('lawful_basis')->nullable()->after('list_source');
            $table->text('lawful_basis_notes')->nullable()->after('lawful_basis');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['list_source', 'lawful_basis', 'lawful_basis_notes']);
        });
    }
};
