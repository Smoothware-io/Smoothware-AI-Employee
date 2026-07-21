<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a campaign from a label into something that can actually work a list.
 *
 * Every pace, limit and window lives HERE rather than in config, because these
 * are the settings a client will want different from ours: a Dutch B2B list and
 * a support callback queue are not the same job, and neither should need a
 * deploy to re-tune.
 *
 * The defaults are deliberately timid. A campaign that dials too slowly wastes a
 * morning; one that dials too fast is a robocall, and the first person to notice
 * is a regulator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // draft -> running -> paused -> completed. Nothing dials in draft.
            $table->string('status', 20)->default('draft')->after('description');

            // Pacing. 6/hour = one every ten minutes: human-paced, and slow
            // enough that a mistake is noticed before it reaches fifty people.
            $table->unsignedSmallInteger('calls_per_hour')->default(6)->after('status');

            // How long a conversation may run before the AI is told to wrap up.
            // A cold call that overruns three minutes has usually stopped being
            // welcome.
            $table->unsignedSmallInteger('max_call_minutes')->default(3);

            // No answer: try again, or move on. Zero means one attempt only.
            $table->unsignedTinyInteger('max_attempts')->default(2);
            $table->unsignedSmallInteger('retry_after_hours')->default(24);

            // Reuse the availability rules, so a campaign cannot ring anyone at
            // 21:00 by forgetting a separate setting.
            $table->boolean('respect_working_hours')->default(true);

            // What the AI is trying to do on these calls. Per campaign, because
            // "introduce us" and "chase a quote" are different conversations.
            $table->text('objective')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // When the runner last placed a call. The pacing clock.
            $table->timestamp('last_dialed_at')->nullable();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'calls_per_hour', 'max_call_minutes', 'max_attempts',
                'retry_after_hours', 'respect_working_hours', 'objective',
                'started_at', 'completed_at', 'last_dialed_at',
            ]);
        });
    }
};
