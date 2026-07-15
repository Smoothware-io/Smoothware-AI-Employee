<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The universal, append-only audit / event log — the backbone of the whole
 * product (Phase 0). Every meaningful mutation across every feature writes one
 * row here via the EventLogger service. This table is the single source for the
 * Company Timeline (Phase 1) and Reporting (Phase 8).
 *
 * Design rules:
 *  - APPEND ONLY. Rows are never updated or deleted. There is intentionally no
 *    updated_at and no soft-delete column.
 *  - actor_type distinguishes a human ('user'), the AI ('ai_agent'), or the
 *    system itself ('system' — jobs, imports, scheduled tasks).
 *  - (entity_type, entity_id) is a loose polymorphic reference. We do NOT add a
 *    foreign key: the referenced row may later be archived/erased, but its
 *    history must survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // What the event is about (loose polymorphic reference).
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();

            // Who caused it.
            $table->string('actor_type', 20); // user | ai_agent | system
            $table->unsignedBigInteger('actor_id')->nullable();

            // What happened, e.g. 'company.created', 'task.status_changed'.
            $table->string('action');

            // Structured detail: before/after diff or a snapshot.
            $table->jsonb('payload')->nullable();

            // Append-only: creation time only, no updated_at.
            $table->timestamp('created_at')->nullable();

            // Primary access pattern: an entity's timeline in chronological order.
            $table->index(['entity_type', 'entity_id', 'created_at']);
            // Secondary: "everything this actor did" (AI ops reporting).
            $table->index(['actor_type', 'actor_id']);
            // Secondary: filter/aggregate by action type (reporting).
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
