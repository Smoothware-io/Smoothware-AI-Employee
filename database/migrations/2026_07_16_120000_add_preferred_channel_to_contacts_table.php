<?php

use App\Enums\PreferredChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a contact prefers to be reached ({@see PreferredChannel}).
 *
 * Nullable, and no default: null means nobody recorded a preference. Defaulting
 * to 'either' would let automation treat "we never asked" as consent to any
 * channel — the same failure the import lawful_basis column exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('preferred_channel', 20)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('preferred_channel');
        });
    }
};
