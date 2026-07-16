<?php

use App\Enums\Language;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What language to speak to this company in ({@see Language}).
 *
 * Nullable, no default. Null means nobody told us — the AI falls back to a guess
 * from `country`, which is a guess and is treated as one. Defaulting the column
 * to 'nl' would turn "we never asked" into "they said Dutch", which is the same
 * mistake `preferred_channel` and `lawful_basis` exist to avoid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('language', 5)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
