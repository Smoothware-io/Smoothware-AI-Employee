<?php

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\Voice\VoiceToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Executes a tool the AI called mid-conversation (ARCHITECTURE §15.6).
 *
 * go-voice forwards {call_id, name, arguments}; we run it against the CRM and
 * return { output }, which the model receives as the function result. This is
 * the ONLY place tools are implemented — go-voice is deliberately ignorant of
 * which tools exist, so a new one is a change here and in the registry, nowhere
 * else.
 */
class ToolController extends Controller
{
    public function __construct(private VoiceToolRegistry $registry) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_id' => ['required', 'string'],
            'name' => ['required', 'string'],
            'arguments' => ['nullable'], // object or JSON — normalised below
        ]);

        // OpenAI sends arguments as a JSON string; go-voice passes it through as
        // raw JSON. Accept either an already-decoded array or a string.
        $args = $data['arguments'] ?? [];
        if (is_string($args)) {
            $args = json_decode($args, true) ?: [];
        }
        if (! is_array($args)) {
            $args = [];
        }

        // The call gives the tool its context — which company, which contact.
        // external_id is the OpenAI call_id we stored when we accepted the call.
        $call = Call::query()
            ->where('external_id', $data['call_id'])
            ->latest('id')
            ->first();

        $output = $this->registry->execute($data['name'], $args, $call);

        // Wrap in { output } — the shape go-voice unwraps and hands the model.
        // Encoded to a string here because a function_call_output.output is a
        // string, not an object.
        return response()->json([
            'output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
