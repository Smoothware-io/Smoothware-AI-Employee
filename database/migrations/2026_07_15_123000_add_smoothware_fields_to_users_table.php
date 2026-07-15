<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Smoothware's cross-cutting user fields:
 *  - is_active:   soft on/off switch for panel access without deleting the user.
 *  - archived_at: soft-delete column (Smoothware never hard-deletes except for
 *                 GDPR erasure). Maps to the User model's DELETED_AT override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('email');
            $table->softDeletes('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes('archived_at');
            $table->dropColumn('is_active');
        });
    }
};
