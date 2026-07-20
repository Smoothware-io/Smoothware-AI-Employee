<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI is on a call: its role, and what it is trying to achieve.
 *
 * One row per direction, because an AI that ANSWERS must not announce a call and
 * an AI that CALLS must not ask "how can I help you?". That text used to be a
 * hardcoded PHP string, which meant changing how the AI introduces itself needed
 * a developer and a deploy — a business decision behind an engineering gate.
 *
 * Deliberately NOT stored here: the Art. 50 disclosure, the hard limits, and the
 * tool instructions. Those are safety invariants, not preferences. Putting them
 * in a form is putting a delete button next to legal compliance and next to the
 * grounding contract that stops the AI inventing facts on a live call, where
 * there is no review queue and no undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_personas', function (Blueprint $table) {
            $table->id();

            // One persona per direction. Unique, so there is never a question of
            // which of two inbound personas the AI is currently using.
            $table->string('direction', 20)->unique(); // inbound|outbound

            $table->text('role');
            $table->text('goal')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_personas');
    }
};
