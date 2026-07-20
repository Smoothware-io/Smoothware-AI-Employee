<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarAccount;
use App\Services\Google\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The two ends of Google's OAuth dance.
 *
 * `state` is a random value we put in the session and check on the way back. It
 * is the CSRF defence for this flow: without it, anyone could hand a logged-in
 * rep a crafted callback URL and attach THEIR Google account to that rep's
 * profile — which would mean our meetings landing in an attacker's calendar and
 * an attacker's busy time silently shaping when the AI books.
 */
class GoogleCalendarOAuthController extends Controller
{
    public function __construct(private GoogleCalendarClient $client) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (blank(config('services.google.client_id'))) {
            return redirect('/admin')->with('error', 'Google Calendar is not configured on this server.');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->client->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');

        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect('/admin/connect-google-calendar')
                ->with('error', 'That Google sign-in could not be verified. Please try again.');
        }

        if ($request->query('error') !== null || blank($request->query('code'))) {
            // The rep pressed "cancel", which is not a failure worth alarming them about.
            return redirect('/admin/connect-google-calendar')
                ->with('error', 'Google access was not granted.');
        }

        $token = $this->client->exchangeCode((string) $request->query('code'));

        if ($token === null || blank($token['access_token'] ?? null)) {
            return redirect('/admin/connect-google-calendar')
                ->with('error', 'Google did not return an access token. Please try again.');
        }

        $account = GoogleCalendarAccount::firstOrNew(['user_id' => Auth::id()]);

        $account->forceFill([
            'access_token' => $token['access_token'],
            // On a RE-connect Google may omit the refresh token. Keeping the old
            // one is what stops reconnecting from quietly breaking the sync.
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'google_email' => $this->client->userEmail($token['access_token']),
            'last_error' => null,
        ])->save();

        return redirect('/admin/connect-google-calendar')
            ->with('success', 'Google Calendar connected.');
    }
}
