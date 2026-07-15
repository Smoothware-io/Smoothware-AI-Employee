<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // Nullable: most tasks belong to a company, but standalone tasks
            // (general to-dos) are allowed.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            $table->string('type', 20);              // call_back|send_proposal|send_email|follow_up
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status', 20)->default('open'); // state machine — see App\Enums\TaskStatus
            $table->string('status_reason')->nullable();    // why blocked/cancelled

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('source', 20)->default('manual');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index(['status', 'due_at']);
            $table->index('assigned_to');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
