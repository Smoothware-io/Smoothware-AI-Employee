<?php

namespace App\Services\Outbound;

use App\Contracts\CallOriginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Originates a call through Asterisk's Manager Interface (AMI).
 *
 * AMI is a plain line-based TCP protocol: send `Key: value` lines, terminate the
 * action with a blank line, read lines back. No SDK, no HTTP — which is why this
 * talks to a socket directly rather than through Http::.
 *
 * The dialplan does the actual work (see the smoothware-voice-sip repo):
 *
 *   Local/<phone>@outbound-guard  ->  country allow-list + concurrency cap
 *                                 ->  place-call: ring the prospect via Sonetel
 *   on answer -> bridge-openai    ->  dial sip:proj_...@sip.api.openai.com
 *
 * Note what is NOT here: any decision about who may be called. That is
 * {@see OutboundGate}, and it runs before this is ever reached. The dialplan then
 * re-checks the country and the cap independently, because a carrier that trusts
 * the application completely is one bug away from dialling a premium line all
 * weekend.
 *
 * AMI is loopback-bound on the Asterisk host. If Laravel runs elsewhere — it
 * should — this reaches it over an SSH tunnel or WireGuard, never the internet.
 * An AMI port on the internet is the single most reliable way to hand someone
 * else your phone bill.
 */
class AsteriskOriginator implements CallOriginator
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $secret,
        private string $context = 'bridge-openai',
        private int $timeout = 10,
    ) {}

    public function originate(string $phone, string $callerId): string
    {
        $actionId = (string) Str::uuid();

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Refusing to dial: cannot reach Asterisk AMI at {$this->host}:{$this->port} ({$errstr})."
            );
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            // AMI greets with a banner before it will accept anything.
            fgets($socket);

            $this->send($socket, [
                'Action' => 'Login',
                'Username' => $this->username,
                'Secret' => $this->secret,
            ]);

            $login = $this->read($socket);

            if (! str_contains($login, 'Success')) {
                // Never log $login on failure: AMI echoes back what it was sent,
                // and that includes the secret.
                throw new RuntimeException('Refusing to dial: Asterisk AMI rejected our credentials.');
            }

            // Local/<phone>@outbound-guard enters the dialplan at the guard, not
            // at the trunk. Dialling PJSIP/<phone>@sonetel directly from here
            // would work and would skip the country allow-list and the
            // concurrency cap — the two fences that hold when Laravel is wrong.
            $this->send($socket, [
                'Action' => 'Originate',
                'ActionID' => $actionId,
                'Channel' => "Local/{$phone}@outbound-guard",
                'Context' => $this->context,
                'Exten' => 's',
                'Priority' => 1,
                'CallerID' => $callerId,
                'Timeout' => 45_000,
                // Async: the prospect's phone rings for 45 seconds. Blocking this
                // request on that would tie up a PHP worker per ring, and the
                // answer arrives over the OpenAI webhook anyway.
                'Async' => 'true',
            ]);

            $response = $this->read($socket);

            if (! str_contains($response, 'Success')) {
                throw new RuntimeException(
                    'Asterisk refused the originate: '.$this->firstMessage($response)
                );
            }

            Log::info('Asterisk originate accepted', [
                'action_id' => $actionId,
                // No phone number: reference logging, same rule as the events log.
                'context' => $this->context,
            ]);

            return $actionId;
        } finally {
            // Leaked AMI sessions accumulate until Asterisk stops accepting new
            // ones, and then every call fails for a reason that looks nothing
            // like "we forgot to log off".
            @$this->send($socket, ['Action' => 'Logoff']);
            @fclose($socket);
        }
    }

    public function name(): string
    {
        return 'asterisk';
    }

    /** @param array<string, string|int> $fields */
    private function send($socket, array $fields): void
    {
        $message = '';

        foreach ($fields as $key => $value) {
            $message .= "{$key}: {$value}\r\n";
        }

        // The blank line is what tells AMI the action is complete. Without it the
        // connection just hangs until the timeout, which reads as a network fault.
        fwrite($socket, $message."\r\n");
    }

    private function read($socket): string
    {
        $buffer = '';

        while (($line = fgets($socket)) !== false) {
            $buffer .= $line;

            // A response ends at a blank line.
            if (rtrim($line, "\r\n") === '' && $buffer !== '') {
                break;
            }

            $meta = stream_get_meta_data($socket);

            if ($meta['timed_out'] ?? false) {
                throw new RuntimeException('Asterisk AMI timed out while we waited for a reply.');
            }
        }

        return $buffer;
    }

    private function firstMessage(string $response): string
    {
        foreach (explode("\n", $response) as $line) {
            if (str_starts_with($line, 'Message:')) {
                return trim(mb_substr($line, 8));
            }
        }

        return 'no reason given';
    }
}
