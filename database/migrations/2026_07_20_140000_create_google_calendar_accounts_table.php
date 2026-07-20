<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rep's connected Google Calendar.
 *
 * Per user, like the Sonetel account: the calendar the AI must not book over is
 * a personal one, and a single shared service account would mean every rep's
 * private appointments are readable by everyone in the CRM.
 *
 * Tokens are encrypted at rest (see the model's casts). A leaked refresh token
 * is standing read/write access to somebody's calendar until they notice and
 * revoke it, which most people never do.
 *
 * NOTE: `appointments.google_event_id` and `google_html_link` already exist from
 * Phase 1, which anticipated this ("two-way OAuth sync is a later decision" —
 * Appointment's docblock). This migration deliberately adds no column there;
 * that decision has now been made and the existing columns carry it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Which Google account this is — shown in the UI so a rep can tell
            // they connected the work one, not the personal one.
            $table->string('google_email')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();

            // 'primary' unless the rep picks another calendar.
            $table->string('calendar_id')->default('primary');

            // Two independent switches: reading their busy time and writing our
            // meetings into their calendar are different permissions in practice,
            // and a rep may well want one without the other.
            $table->boolean('block_from_busy')->default(true);
            $table->boolean('push_appointments')->default(true);

            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_accounts');
    }
};
