<?php

namespace App\Services\Receptionist;

use App\Contracts\ReceptionistLlm;
use App\Enums\CallIntent;
use App\Support\Receptionist\ReceptionistAnalysis;
use Illuminate\Support\Facades\Http;

/**
 * Production receptionist LLM — Anthropic Claude (Opus 4.8) via the Messages
 * API, using structured outputs (output_config.format) so the model returns a
 * schema-validated JSON analysis. It is given ONLY the retrieved KB chunks and
 * must cite the ones it used; the orchestrator validates those citations to
 * enforce grounding.
 *
 * Not exercised in tests/CI (no API key) — the FakeReceptionistLlm covers those.
 * Wired only when LLM_DRIVER=claude. Uses the documented REST shape; can be
 * swapped for the official anthropic-ai/sdk when convenient.
 */
class ClaudeReceptionistLlm implements ReceptionistLlm
{
    public function __construct(
        private string $apiKey,
        private string $model = 'claude-opus-4-8',
        private string $effort = 'high',
    ) {}

    public function analyze(string $transcript, array $chunks, array $rules): ReceptionistAnalysis
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 2048,
            'thinking' => ['type' => 'adaptive'],
            'output_config' => [
                'effort' => $this->effort,
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
            'system' => $this->systemPrompt($chunks, $rules),
            'messages' => [[
                'role' => 'user',
                'content' => "Call transcript:\n\n".$transcript,
            ]],
        ])->throw()->json();

        return $this->parse($response);
    }

    /**
     * @param  array<int, array{id: int, content: string}>  $chunks
     * @param  array<int, string>  $rules
     */
    private function systemPrompt(array $chunks, array $rules): string
    {
        $rulesText = $rules === []
            ? '(no rules configured)'
            : collect($rules)->map(fn (string $r): string => "- {$r}")->implode("\n");

        $chunksText = $chunks === []
            ? '(no knowledge-base excerpts were retrieved for this call)'
            : collect($chunks)
                ->map(fn (array $c): string => "[chunk {$c['id']}]\n{$c['content']}")
                ->implode("\n\n");

        return <<<PROMPT
        You are the AI receptionist for Smoothware, a web & software agency. You are
        analysing a completed inbound call transcript to draft CRM records for a human
        to review. You do not speak to the caller.

        HARD RULE — GROUNDING: You may only provide an `answer` to the caller's
        question using the knowledge-base excerpts below. If the excerpts do not
        contain the answer, set `answer` to null and leave `used_chunk_ids` empty —
        do NOT improvise or use outside knowledge. When you do answer, list the exact
        chunk ids you used in `used_chunk_ids`.

        BUSINESS RULES (active ruleset):
        {$rulesText}

        KNOWLEDGE-BASE EXCERPTS:
        {$chunksText}

        Return a JSON object matching the required schema: the detected intent, a
        short summary of the call, a grounded answer (or null), the cited chunk ids,
        the caller's company and contact names if stated, an optional follow-up task
        title, and your confidence (0-1).
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => array_map(fn (CallIntent $i) => $i->value, CallIntent::cases())],
                'summary' => ['type' => 'string'],
                'answer' => ['type' => ['string', 'null']],
                'used_chunk_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'company_name' => ['type' => ['string', 'null']],
                'contact_first_name' => ['type' => ['string', 'null']],
                'contact_last_name' => ['type' => ['string', 'null']],
                'proposed_task_title' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['intent', 'summary', 'answer', 'used_chunk_ids', 'confidence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function parse(array $response): ReceptionistAnalysis
    {
        $text = collect($response['content'] ?? [])
            ->firstWhere('type', 'text')['text'] ?? '{}';
        $data = json_decode($text, true) ?: [];

        return new ReceptionistAnalysis(
            intent: CallIntent::tryFrom($data['intent'] ?? '') ?? CallIntent::Other,
            summary: (string) ($data['summary'] ?? ''),
            answer: $data['answer'] ?? null,
            usedChunkIds: array_map('intval', $data['used_chunk_ids'] ?? []),
            companyName: $data['company_name'] ?? null,
            contactFirstName: $data['contact_first_name'] ?? null,
            contactLastName: $data['contact_last_name'] ?? null,
            proposedTaskTitle: $data['proposed_task_title'] ?? null,
            confidence: (float) ($data['confidence'] ?? 0.0),
            inputTokens: (int) ($response['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($response['usage']['output_tokens'] ?? 0),
        );
    }
}
