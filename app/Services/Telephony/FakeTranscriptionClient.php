<?php

namespace App\Services\Telephony;

use App\Contracts\TranscriptionClient;
use App\Models\Call;

/**
 * Offline transcription for dev/tests. Returns the call's existing transcript if
 * the provider already supplied one, else a canned line. The real client
 * (Sonetel-supplied vs. Whisper/Deepgram) is deferred.
 */
class FakeTranscriptionClient implements TranscriptionClient
{
    public function transcribe(Call $call): string
    {
        return $call->transcript ?? 'Caller: Hello, I have a question about your services. Agent: Sure, how can I help?';
    }
}
