<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The do-not-contact list. "Never contact me again", made enforceable.
 *
 * THIS TABLE MUST OUTLIVE EVERYTHING ELSE — and that is the whole design.
 *
 * Consider the sequence this exists to prevent: someone says "delete my data and
 * never call me again". We honour the erasure, their contact row disappears...
 * and with it the only record that they ever objected. Next month the same
 * purchased list is re-imported and we call them again. The erasure request
 * caused the violation.
 *
 * So a suppression is deliberately NOT linked to a contact/company by FK (the
 * FK would cascade away), and there is NO `archived_at`. It stores the
 * normalised ADDRESS on its own. Keeping it is not a loophole — honouring an
 * objection is a legal obligation, and GDPR Art. 17(3)(b) contemplates retaining
 * exactly what compliance requires. It should still be named to counsel
 * (GO-LIVE-LEGAL): a permanent list of contact details is itself processing.
 *
 * Mistakes are corrected by RELEASING (`released_at` + a reason + who), never by
 * deleting. An un-suppression is a consequential act and must leave a trace —
 * "who let us start calling this person again, and why?" has to be answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20);                  // App\Enums\SuppressionType
            // The matchable form. Raw input is kept alongside it so a human can
            // see what was actually typed when normalisation is questioned.
            $table->string('value_normalized');
            $table->string('value_raw')->nullable();

            $table->string('source', 20)->default('manual'); // App\Enums\SuppressionSource
            $table->text('reason')->nullable();

            $table->timestamp('suppressed_at')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Released = "this was a mistake / they re-consented". The row stays.
            $table->timestamp('released_at')->nullable();
            $table->text('released_reason')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One live suppression per address. The partial index lets the same
            // address be re-suppressed after a release without a clash, while
            // making a duplicate ACTIVE entry impossible.
            $table->index(['type', 'value_normalized']);
        });

        // A partial unique index: one ACTIVE suppression per address, enforced by
        // the ENGINE rather than by code remembering to check — the same lesson
        // as follow_ups.dedup_key. Postgres and SQLite both support this.
        DB::statement(
            'CREATE UNIQUE INDEX suppressions_active_unique
             ON suppressions (type, value_normalized)
             WHERE released_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
