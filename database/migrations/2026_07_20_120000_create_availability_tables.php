<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the AI is allowed to book, and when it must not.
 *
 * Two tables because they answer different questions:
 *   - availability_rules: the RECURRING shape of a week ("Mondays 09:00-17:00")
 *   - availability_blocks: ONE-OFF exclusions ("closed 24 Dec", "at a conference")
 *
 * Both carry a NULLABLE user_id from day one. Null means company-wide, which is
 * all today's single-rep setup needs. Per-rep availability later is then filling
 * that column in, not migrating live appointments to a new shape — the change
 * that would otherwise have to happen while real meetings are already booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();

            // Null = applies to the whole company. Set = that rep's own hours.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // 1 (Monday) .. 7 (Sunday), matching Carbon's ISO weekday.
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'weekday', 'is_active']);
        });

        Schema::create('availability_blocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The lookup the booking tool makes on every call, while a caller is
            // holding the line.
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_blocks');
        Schema::dropIfExists('availability_rules');
    }
};
