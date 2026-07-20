<?php

namespace App\Services\Voice;

use App\Enums\CallDirection;
use App\Enums\PersonaPreset;
use App\Services\KnowledgeRetriever;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Drafts a call persona from a preset, grounded in the knowledge base.
 *
 * Two things make this safe rather than a gimmick:
 *
 *  - It DRAFTS. The text lands in the form for a human to read, edit and save.
 *    Nothing generated here reaches a caller until a person has approved it,
 *    which is the same propose→approve contract as everywhere else (§14). An
 *    AI writing its own instructions and adopting them unread is exactly the
 *    loop we do not want.
 *  - It cannot widen what the AI may do. The disclosure, the hard limits and the
 *    tool rules live in CallInstructionBuilder and are appended AFTER whatever
 *    this produces. The worst a bad draft can do is be unhelpful.
 */
class PersonaWriter
{
    public function __construct(private KnowledgeRetriever $retriever) {}

    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * @return array{role: string, goal: string}
     */
    public function draft(PersonaPreset $preset, CallDirection $direction, ?string $extra = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('No AI provider is connected, so instructions cannot be generated. Write them by hand, or ask an administrator to set ANTHROPIC_API_KEY.');
        }

        // Ground it in what we actually know about ourselves. A persona invented
        // from nothing describes a company that does not exist, and the model
        // will happily say those things out loud on a real call.
        $chunks = collect($this->retriever->retrieve('diensten werkwijze bedrijf klanten', 6))
            ->map(fn (array $hit): string => trim($hit['chunk']->content))
            ->filter()
            ->implode("\n\n");

        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => (string) config('services.anthropic.model', 'claude-opus-4-8'),
            'max_tokens' => 1500,
            'thinking' => ['type' => 'adaptive'],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'role' => ['type' => 'string'],
                            'goal' => ['type' => 'string'],
                        ],
                        'required' => ['role', 'goal'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'system' => $this->systemPrompt($chunks),
            'messages' => [[
                'role' => 'user',
                'content' => $this->brief($preset, $direction, $extra),
            ]],
        ])->throw()->json();

        return $this->parse($response);
    }

    private function systemPrompt(string $knowledge): string
    {
        return <<<TXT
        You write the persona for a voice AI that answers and places real phone
        calls for Smoothware, a Dutch web and software agency.

        Produce exactly two pieces of text:
          - role: who the AI is and how it behaves on this kind of call
          - goal: what a good call achieves, in one or two sentences

        Rules you must follow:
        - Write in DUTCH. These calls are with Dutch businesses.
        - Address the AI directly as "je", the way the existing instructions do.
        - Use ONLY the company facts below. Invent no service, price, client or
          claim. If the knowledge base is thin, write a persona that is honest
          about knowing little rather than one that bluffs.
        - Never mention prices, discounts, deadlines or guarantees.
        - Do NOT restate legal disclosures, safety limits or tool instructions.
          Those are added separately and are not yours to write; repeating them
          here would create two versions of wording that must never differ.
        - Keep role under 120 words and goal under 40. This is spoken aloud.

        WHAT WE KNOW ABOUT THE COMPANY:
        {$knowledge}
        TXT;
    }

    private function brief(PersonaPreset $preset, CallDirection $direction, ?string $extra): string
    {
        $lines = [
            'Write the persona for: '.$preset->brief().'.',
            $direction === CallDirection::Inbound
                ? 'This is an INBOUND call: the person rang US. The AI must not announce a call or explain why it is phoning.'
                : 'This is an OUTBOUND call: WE rang them. The AI must respect that it interrupted them.',
            'The intended outcome is: '.$preset->goal(),
        ];

        if (filled($extra)) {
            $lines[] = 'Additional instruction from the team: '.$extra;
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{role: string, goal: string}
     */
    private function parse(array $response): array
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parsed = json_decode((string) $block['text'], true);

                if (is_array($parsed) && filled($parsed['role'] ?? null)) {
                    return [
                        'role' => trim((string) $parsed['role']),
                        'goal' => trim((string) ($parsed['goal'] ?? '')),
                    ];
                }
            }
        }

        throw new RuntimeException('The AI did not return usable instructions. Try again, or write them by hand.');
    }
}
