<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppressed rows get their own counter in the import preview.
 *
 * Rolled up separately from `skip_count` on purpose: "12 blank lines" and
 * "12 people who told us never to contact them again" are not the same fact, and
 * a rep about to commit a batch should see the second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('suppressed_count')->default(0)->after('invalid_count');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn('suppressed_count');
        });
    }
};
