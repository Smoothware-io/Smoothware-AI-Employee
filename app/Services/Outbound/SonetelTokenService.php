<?php

namespace App\Services\Outbound;

use App\Models\SonetelAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Connects a rep's Sonetel account and keeps its token alive.
 *
 * Verified against Sonetel's own Python SDK (github.com/Sonetel/sonetel-python,
 * read 2026-07-16) rather than inferred, because three details are not guessable
 * and all three are silent failures if wrong:
 *
 *  - AUTH AND API ARE DIFFERENT HOSTS. Tokens come from `api.sonetel.com`;
 *    everything else lives on `public-api.sonetel.com`.
 *  - Basic auth uses a FIXED PUBLIC CLIENT, `sonetel-api:sonetel-api` — it is not
 *    the rep's credentials, which go in the form body instead.
 *  - The body is form-urlencoded, and `refresh=yes` is what makes Sonetel return
 *    a refresh token at all. Without it you get a 24-hour token and no way to
 *    renew it, so calls stop dead the next day.
 *
 * The rep's password is used once and never stored — see the migration.
 */
class SonetelTokenService
{
    private const AUTH_URL = 'https://api.sonetel.com/SonetelAuth/beta/oauth/token';

    /** Sonetel's public OAuth client. Not a secret, not the rep's login. */
    private const CLIENT_ID = 'sonetel-api';

    private const CLIENT_SECRET = 'sonetel-api';

    /**
     * Exchange a rep's Sonetel login for tokens and store them.
     *
     * @throws RuntimeException with Sonetel's own message, so a rep sees "wrong
     *                          password" rather than "something went wrong".
     */
    public function connect(User $user, string $username, string $password): SonetelAccount
    {
        $token = $this->requestToken([
            'grant_type' => 'password',
            'refresh' => 'yes',
            'username' => $username,
            'password' => $password,
        ]);

        return SonetelAccount::updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'username' => $username,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
                'connected_at' => now(),
                'last_refreshed_at' => now(),
                // Deliberately absent: the password. It was used once, above.
            ],
        );
    }

    /**
     * A usable access token for this user, refreshing if it is close to expiry.
     *
     * Returns null rather than throwing when there is nothing to work with — a
     * rep who has not connected is a normal state, not an error.
     */
    public function tokenFor(User $user): ?string
    {
        $account = SonetelAccount::firstWhere('user_id', $user->getKey());

        if ($account === null) {
            return null;
        }

        if ($account->hasFreshToken()) {
            return $account->access_token;
        }

        if (! $account->canRefresh()) {
            return null; // must reconnect with a password
        }

        return $this->refresh($account)?->access_token;
    }

    /**
     * Swap the refresh token for a new access token.
     *
     * A failure here is expected eventually — refresh tokens are revoked, expire,
     * or are invalidated by a password change. It clears the stored token so the
     * UI says "reconnect" instead of silently failing every call from then on.
     */
    public function refresh(SonetelAccount $account): ?SonetelAccount
    {
        try {
            $token = $this->requestToken([
                'grant_type' => 'refresh_token',
                'refresh' => 'yes',
                'refresh_token' => $account->refresh_token,
            ]);
        } catch (RuntimeException) {
            $account->forceFill([
                'access_token' => '',
                'refresh_token' => null,
                'expires_at' => null,
            ])->save();

            return null;
        }

        $account->forceFill([
            'access_token' => $token['access_token'],
            // Sonetel may or may not rotate the refresh token; keep the old one
            // when it does not, or we would lose the ability to refresh again.
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
            'last_refreshed_at' => now(),
        ])->save();

        return $account;
    }

    /**
     * @param  array<string, string|null>  $body
     * @return array<string, mixed>
     */
    private function requestToken(array $body): array
    {
        $response = Http::asForm()
            ->withBasicAuth(self::CLIENT_ID, self::CLIENT_SECRET)
            ->timeout(30)
            ->post(self::AUTH_URL, array_filter($body));

        if ($response->failed()) {
            throw new RuntimeException($this->errorFrom($response->json(), $response->status()));
        }

        $token = $response->json();

        if (! is_array($token) || blank($token['access_token'] ?? null)) {
            throw new RuntimeException('Sonetel returned no access token.');
        }

        return $token;
    }

    /** Sonetel's message where there is one — a rep can act on "Bad credentials". */
    private function errorFrom(mixed $body, int $status): string
    {
        $message = is_array($body)
            ? ($body['error_description'] ?? $body['message'] ?? $body['error'] ?? null)
            : null;

        return $message !== null
            ? "Sonetel: {$message}"
            : "Sonetel rejected the login (HTTP {$status}).";
    }
}
