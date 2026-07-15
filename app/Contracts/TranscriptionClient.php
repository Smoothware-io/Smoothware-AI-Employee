<?php

namespace App\Contracts;

use App\Models\Call;

/**
 * Speech-to-text for a call recording. When the telephony provider already
 * supplies a transcript, the pipeline uses that; otherwise it transcribes the
 * recording here. The concrete provider (Sonetel-supplied vs. Whisper/Deepgram)
 * is a later decision — hence the seam.
 */
interface TranscriptionClient
{
    public function transcribe(Call $call): string;
}
