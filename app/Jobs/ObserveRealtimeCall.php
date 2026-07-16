<?php

namespace App\Jobs;

use App\Models\Call;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * Rides along on a live OpenAI Realtime call and writes down what was said.
 *
 * OpenAI is already doing speech-to-text to hold the conversation at all, so the
 * transcript is a by-product we can simply read — no Whisper, no Deepgram, and
 * none of Sonetel's paid recorder. This is what closes the Phase 3 gap where
 * `TranscriptionClient` had no real implementation.
 *
 * It runs as a queued job because a webhook must answer in milliseconds while
 * this holds a socket open for the length of the call.
 *
 * DELIBERATELY ALSO AN INSTRUMENT. Whether audio deltas reach an observer of a
 * SIP call is unverified — the caller's audio arrives at OpenAI over RTP and may
 * never be echoed here, and there are open reports of the audio events not
 * firing at all. So every event TYPE is counted and logged. One real call
 * answers the question that no amount of reading the docs has.
 */
class ObserveRealtimeCall implements ShouldQueue
{
    use Queueable;

    /** A call outlives a default job timeout. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $callId, public ?int $callRecordId = null) {}

    public function handle(): void
    {
        $call = $this->callRecordId ? Call::find($this->callRecordId) : null;

        $transcript = [];
        $eventCounts = [];
        $audioBytes = 0;

        try {
            $client = new Client('wss://api.openai.com/v1/realtime?call_id='.$this->callId);
            $client
                ->addHeader('Authorization', 'Bearer '.config('outbound.openai.key'))
                ->addMiddleware(new CloseHandler)
                ->addMiddleware(new PingResponder)
                ->setTimeout(120);

            while (true) {
                $message = $client->receive();

                if ($message === null) {
                    break;
                }

                $event = json_decode($message->getContent(), true);

                if (! is_array($event) || ! isset($event['type'])) {
                    continue;
                }

                $type = (string) $event['type'];
                $eventCounts[$type] = ($eventCounts[$type] ?? 0) + 1;

                // Audio, IF it ever arrives. Counted rather than kept: proving it
                // exists is this pass's job; storing it is a decision that needs
                // the GDPR pipeline (object store + retention + eraser), not an
                // 18MB surprise in a queue worker's memory.
                if (str_contains($type, 'audio') && isset($event['delta'])) {
                    $audioBytes += strlen((string) $event['delta']);
                }

                if ($line = $this->transcriptLine($event)) {
                    $transcript[] = $line;
                    $this->persist($call, $transcript);
                }

                if (in_array($type, ['error', 'session.closed'], true)) {
                    Log::info('Realtime observer stopping', ['type' => $type, 'event' => $event]);
                    break;
                }
            }

            $client->close();
        } catch (Throwable $e) {
            // Never let the observer kill the call — it is a passenger, not the
            // driver. A lost transcript is bad; a dropped conversation is worse.
            Log::warning('Realtime observer ended', [
                'call_id' => $this->callId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->persist($call, $transcript, done: true);

        // THE ANSWER to "can we record from here?" — every event type that really
        // arrived, and how much audio came with them.
        Log::info('Realtime observer summary', [
            'call_id' => $this->callId,
            'event_types' => $eventCounts,
            'audio_delta_bytes' => $audioBytes,
            'transcript_lines' => count($transcript),
        ]);
    }

    /**
     * Both sides, in the order they were said.
     *
     * The caller's words arrive as an input-audio TRANSCRIPTION event (OpenAI
     * transcribing them); the AI's arrive as its own output transcript.
     */
    private function transcriptLine(array $event): ?string
    {
        $type = (string) $event['type'];

        // The caller.
        if (str_contains($type, 'input_audio_transcription.completed')) {
            return filled($event['transcript'] ?? null)
                ? 'CALLER: '.trim((string) $event['transcript'])
                : null;
        }

        // The AI, once a whole utterance is done (deltas would be word-by-word).
        if (str_contains($type, 'audio_transcript.done') || $type === 'response.output_audio_transcript.done') {
            return filled($event['transcript'] ?? null)
                ? 'AI: '.trim((string) $event['transcript'])
                : null;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $transcript
     */
    private function persist(?Call $call, array $transcript, bool $done = false): void
    {
        if ($call === null || $transcript === []) {
            return;
        }

        // Written to the ENCRYPTED transcript column, with the retention clock
        // started — this is a real person's words now, not a placeholder.
        $call->forceFill([
            'transcript' => implode("\n", $transcript),
            'transcript_status' => $done ? 'done' : 'processing',
            'retention_expires_at' => $call->retention_expires_at
                ?? now()->addDays((int) config('receptionist.calls.retention_days', 90)),
        ])->saveQuietly();
    }
}
