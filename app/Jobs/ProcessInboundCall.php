<?php

namespace App\Jobs;

use App\Contracts\TranscriptionClient;
use App\Models\Call;
use App\Services\Receptionist\ReceptionistPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Post-call receptionist pipeline (shadow mode): transcribe if needed, then run
 * the grounded analysis + draft creation. Queued so a webhook returns fast.
 */
class ProcessInboundCall implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $callId) {}

    public function handle(TranscriptionClient $transcriber, ReceptionistPipeline $pipeline): void
    {
        $call = Call::find($this->callId);

        if (! $call) {
            return;
        }

        $transcript = $call->transcript ?: $transcriber->transcribe($call);

        if (! $call->transcript && $transcript !== '') {
            $call->forceFill([
                'transcript' => $transcript,
                'transcript_status' => 'done',
            ])->saveQuietly();
        }

        if ($transcript === '') {
            return;
        }

        $pipeline->process($call, $transcript);
    }
}
