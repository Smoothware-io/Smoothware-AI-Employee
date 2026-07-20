<?php

namespace App\Console\Commands;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Services\Voice\CallFinalizer;
use Illuminate\Console\Command;

/**
 * Closes calls that never reported an ending.
 *
 * The normal close happens when go-voice posts the transcript on hang-up. But if
 * the gateway crashes, the network drops, or OpenAI closes the socket without a
 * final event, nothing ever posts — and the call sits at "In progress" forever.
 * That is not a display bug: reporting cannot count a call with no outcome, and
 * a stuck row looks identical to a conversation happening right now.
 *
 * Marked FAILED, not completed. We genuinely do not know how that call ended, and
 * recording a guess as a success would corrupt the one number the business cares
 * about. A human can correct it; an invented success is invisible.
 */
class CloseStaleCalls extends Command
{
    protected $signature = 'calls:close-stale {--minutes=30 : Age after which a live call is considered abandoned}';

    protected $description = 'Close calls stuck in a live state because nothing ever reported their ending';

    public function handle(CallFinalizer $finalizer): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $stale = Call::query()
            ->whereIn('status', [CallStatus::InProgress->value, CallStatus::Dialing->value])
            ->whereNull('ended_at')
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $call) {
            $finalizer->close($call, CallStatus::Failed);
        }

        $this->info("Closed {$stale->count()} stale call(s) older than {$minutes} minutes.");

        return self::SUCCESS;
    }
}
