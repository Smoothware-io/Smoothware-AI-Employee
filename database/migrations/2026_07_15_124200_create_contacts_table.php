<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('job_title')->nullable();
            $table->boolean('is_decision_maker')->default(false);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->string('source', 20)->default('manual');
            $table->foreignId('ai_action_id')->nullable()->constrained('ai_actions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes('archived_at');

            $table->index('company_id');
            $table->index('email');
            $table->index('phone');
            $table->index('is_decision_maker');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
