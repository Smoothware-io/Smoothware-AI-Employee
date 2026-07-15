<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();

            // Google Calendar: v1 is link-out only. These columns are ready for
            // future two-way OAuth sync without a migration.
            $table->string('google_event_id')->nullable();
            $table->string('google_html_link')->nullable();

            $table->string('source', 20)->default('manual');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index('starts_at');
            $table->index('company_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
