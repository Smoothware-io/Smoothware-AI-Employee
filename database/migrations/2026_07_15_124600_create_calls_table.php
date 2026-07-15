<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call history. Phase 1 is manual logging (direction, status, duration). The
 * recording/transcript/consent/retention columns are added NOW but stay dormant
 * until Phase 3 (per the brief: add nullable columns rather than migrate later),
 * and are designed for GDPR:
 *   - recording BYTES never live here — recording_path is an object-store key.
 *   - transcript/summary are encrypted at rest (see the Call model casts).
 *   - retention_expires_at drives a purge job; content_erased_at/erased_by record
 *     erasure. Call METADATA survives erasure for reporting; PII content does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('direction', 10);   // inbound|outbound
            $table->string('status', 20);      // completed|missed|no_answer|busy|failed|voicemail
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('summary')->nullable(); // encrypted at rest

            // --- Phase 3: telephony (Sonetel) + AI. Nullable/dormant for now. ---
            $table->string('external_provider')->nullable(); // e.g. 'sonetel'
            $table->string('external_id')->nullable();        // provider call id
            $table->string('recording_disk')->nullable();     // object-store disk
            $table->string('recording_path')->nullable();     // object-store key
            $table->unsignedBigInteger('recording_bytes')->nullable();
            $table->longText('transcript')->nullable();        // encrypted at rest
            $table->string('transcript_status')->nullable();   // pending|processing|done|failed
            $table->boolean('consent_obtained')->nullable();
            $table->string('consent_method')->nullable();      // ivr_disclosure|verbal|...
            $table->timestamp('disclosed_at')->nullable();
            $table->timestamp('retention_expires_at')->nullable();

            // GDPR erasure trail.
            $table->timestamp('content_erased_at')->nullable();
            $table->foreignId('erased_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 20)->default('manual');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index(['company_id', 'started_at']);
            $table->index('direction');
            $table->index('status');
            $table->index('external_id');
            $table->index('retention_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
