<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Calendar over plain HTTP.
 *
 * No SDK on purpose: this needs exactly four calls (token exchange, refresh,
 * freeBusy, events). google/apiclient is a very large dependency to carry, and
 * upgrade-coupling the whole CRM to it for four endpoints is a bad trade — the
 * same reasoning already applied to SonetelTokenService.
 *
 * Every method fails SOFT and returns null/false rather than throwing. These run
 * while a caller is on the line; a Google outage must degrade the answer, never
 * end the conversation.
 */
class GoogleCalendarClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const API = 'https://www.googleapis.com/calendar/v3';

    /** Where we send a rep to grant access. */
    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => (string) config('services.google.redirect'),
            'response_type' => 'code',
            // calendar.events lets us write our meetings; readonly would let us
            // see busy time but not put anything in. Both halves are wanted.
            'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly openid email',
            // offline + consent is what makes Google return a REFRESH token.
            // Without them the connection silently dies after an hour and the
            // rep's calendar quietly stops blocking anything.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Swap the one-time code for tokens.
     *
     * @return array<string, mixed>|null
     */
    public function exchangeCode(string $code): ?array
    {
        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'redirect_uri' => (string) config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            Log::warning('google calendar: code exchange failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    /**
     * A usable access token, refreshing when it is close to expiry.
     *
     * Returns null when the connection is dead (revoked, expired refresh token).
     * The caller treats that as "this rep has no calendar" rather than an error —
     * the CRM's own appointments still prevent internal double-booking.
     */
    public function accessToken(GoogleCalendarAccount $account): ?string
    {
        if ($account->hasFreshToken()) {
            return $account->access_token;
        }

        if (blank($account->refresh_token)) {
            return null;
        }

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            // Expected eventually: refresh tokens get revoked from Google's own
            // security page. Record it so the UI can say "reconnect" instead of
            // silently never blocking anything again.
            $account->forceFill([
                'access_token' => null,
                'expires_at' => null,
                'last_error' => 'Google refused to refresh the connection. Please reconnect.',
            ])->save();

            return null;
        }

        $token = $response->json();

        $account->forceFill([
            'access_token' => $token['access_token'] ?? null,
            // Google rotates the refresh token only sometimes; keeping the old one
            // when it does not is what stops the connection dying on the next hop.
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'last_error' => null,
        ])->save();

        return $account->access_token;
    }

    /**
     * Busy periods in the rep's calendar between two moments.
     *
     * @return array<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    public function busyPeriods(GoogleCalendarAccount $account, Carbon $from, Carbon $until): array
    {
        $token = $this->accessToken($account);

        if ($token === null) {
            return [];
        }

        // Short timeout: this runs inside get_available_times while a human waits
        // in silence. A slow calendar must cost a moment, not the call.
        $response = Http::withToken($token)
            ->timeout((int) config('services.google.timeout', 5))
            ->post(self::API.'/freeBusy', [
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $until->toRfc3339String(),
                'items' => [['id' => $account->calendar_id ?: 'primary']],
            ]);

        if ($response->failed()) {
            Log::warning('google calendar: freeBusy failed', [
                'account' => $account->getKey(),
                'status' => $response->status(),
            ]);

            return [];
        }

        $calendars = $response->json('calendars', []);
        $busy = $calendars[$account->calendar_id ?: 'primary']['busy'] ?? [];

        return collect($busy)
            ->map(fn (array $slot): array => [
                'starts_at' => Carbon::parse($slot['start']),
                'ends_at' => Carbon::parse($slot['end']),
            ])
            ->all();
    }

    /**
     * Put a meeting in the rep's calendar.
     *
     * Returns the event id AND its htmlLink — Phase 1 already made a column for
     * that link (appointments.google_html_link) so a human can jump straight to
     * the event instead of hunting for it.
     *
     * @param  array<int, string>  $attendeeEmails
     * @return array{id: string, html_link: ?string}|null
     */
    public function createEvent(
        GoogleCalendarAccount $account,
        string $title,
        Carbon $startsAt,
        Carbon $endsAt,
        ?string $description = null,
        array $attendeeEmails = [],
    ): ?array {
        $token = $this->accessToken($account);

        if ($token === null) {
            return null;
        }

        $payload = [
            'summary' => $title,
            'description' => $description,
            'start' => ['dateTime' => $startsAt->toRfc3339String()],
            'end' => ['dateTime' => $endsAt->toRfc3339String()],
        ];

        if ($attendeeEmails !== []) {
            $payload['attendees'] = array_map(fn (string $e): array => ['email' => $e], $attendeeEmails);
        }

        $response = Http::withToken($token)
            ->timeout(15)
            ->post(self::API.'/calendars/'.urlencode($account->calendar_id ?: 'primary').'/events', $payload);

        if ($response->failed()) {
            Log::warning('google calendar: event create failed', [
                'account' => $account->getKey(),
                'status' => $response->status(),
            ]);

            return null;
        }

        $id = $response->json('id');

        return $id === null ? null : ['id' => $id, 'html_link' => $response->json('htmlLink')];
    }

    public function deleteEvent(GoogleCalendarAccount $account, string $eventId): bool
    {
        $token = $this->accessToken($account);

        if ($token === null) {
            return false;
        }

        $response = Http::withToken($token)
            ->timeout(15)
            ->delete(self::API.'/calendars/'.urlencode($account->calendar_id ?: 'primary').'/events/'.urlencode($eventId));

        // 410 Gone means somebody already deleted it in Google. That is the
        // outcome we wanted, so it is a success, not a failure to retry forever.
        return $response->successful() || $response->status() === 410;
    }

    /** The signed-in Google address, for showing which account is connected. */
    public function userEmail(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        return $response->successful() ? $response->json('email') : null;
    }
}
