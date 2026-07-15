<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI's detected intent for a call, annotated by the receptionist pipeline.
 * This is an AI-sourced annotation on a factual call record — not an
 * externally-consequential record creation, which still requires human approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('intent')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('intent');
        });
    }
};
