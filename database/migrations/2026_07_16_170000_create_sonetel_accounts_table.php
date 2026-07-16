<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One rep's connected Sonetel account.
 *
 * Per user rather than one set of credentials in .env: the caller ID a prospect
 * sees should belong to the person responsible for the call, and a rep leaving
 * the company should take their telephony access with them — deleting the user
 * cascades this away.
 *
 * THE PASSWORD IS NEVER STORED. It is exchanged once for tokens and discarded.
 * Storing it would mean a database leak hands over the ability to place calls
 * billed to a real Sonetel account, and password reuse would put the rep's other
 * accounts at risk. The refresh token is the durable credential; it is scoped to
 * this one API and can be revoked at Sonetel without touching anything else.
 *
 * Both tokens are ENCRYPTED at rest (see the model's casts) — same treatment as
 * call transcripts. `expires_in` from the API is seconds-from-issue and is
 * meaningless once stored, so it is resolved to an absolute `expires_at` here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sonetel_accounts', function (Blueprint $table) {
            $table->id();

            // One account per user. Reconnecting updates in place.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('username');              // the Sonetel login email
            $table->string('sonetel_number')->nullable(); // caller ID; null = let Sonetel choose

            // Encrypted — text, not string: ciphertext is far longer than the token.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sonetel_accounts');
    }
};
