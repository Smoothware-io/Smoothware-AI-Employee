<?php

namespace App\Services\Outbound;

use App\Contracts\CallOriginator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The originator the whole test suite runs against, and the default binding.
 *
 * This is not a convenience. Twice today .env leaked into the test suite — first
 * the AI drivers, so tests made real Claude calls; then OUTBOUND_ENABLED=true,
 * which meant a test run could have dialled a real person. The defence is that
 * the real originator is never the default: you get this one unless a human
 * deliberately configures otherwise.
 *
 * Records what it was asked to do so tests can assert on it without a socket.
 */
class FakeCallOriginator implements CallOriginator
{
    /** @var list<array{phone: string, caller_id: string, action_id: string}> */
    public array $originated = [];

    public bool $shouldFail = false;

    public function originate(string $phone, string $callerId): string
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Asterisk refused the originate: fake failure.');
        }

        $actionId = (string) Str::uuid();

        $this->originated[] = [
            'phone' => $phone,
            'caller_id' => $callerId,
            'action_id' => $actionId,
        ];

        return $actionId;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function lastPhone(): ?string
    {
        return $this->originated === [] ? null : end($this->originated)['phone'];
    }
}
