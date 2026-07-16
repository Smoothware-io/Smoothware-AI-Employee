<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human-authored follow-up automation (Phase 7).
 *
 * A rule is a STANDING INSTRUCTION written by a human, which is why rule-created
 * tasks do not go through the AI approval queue: a person already decided, in
 * advance. Authoring is restricted to sales_manager / super_admin — a rule
 * creates work for other people without their per-instance consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_rules', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('trigger', 40);          // App\Enums\FollowUpTrigger
            $table->jsonb('conditions')->nullable(); // e.g. {"company_status":["lead"],"campaign_id":3}

            // How long after the trigger the resulting task is due.
            $table->unsignedInteger('delay_minutes')->default(0);

            // The task this rule creates (mirrors the Phase 1 tasks table).
            $table->string('task_type', 20)->default('follow_up');
            $table->string('task_title');               // supports {company.name} placeholders
            $table->text('task_description')->nullable();

            $table->string('assignee_strategy', 20)->default('company_owner'); // App\Enums\AssigneeStrategy
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            // The evaluator's hot path: active rules for a given trigger.
            $table->index(['trigger', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_rules');
    }
};
