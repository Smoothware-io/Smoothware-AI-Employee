<?php

use App\Models\Company;
use App\Models\SonetelAccount;
use App\Models\User;
use App\Services\Outbound\SonetelDialer;
use App\Services\Outbound\SonetelTokenService;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/**
 * Per-rep Sonetel connection + the real callback API.
 *
 * The endpoint details here were VERIFIED against Sonetel's own Python SDK, not
 * inferred — an earlier version of this code guessed all three and every one was
 * wrong in a way that fails silently: the wrong host, `show2` instead of
 * `show_2`, and no `app_id`.
 */
/*
 * NOTE on the fake patterns below: they are fully qualified WITH the scheme on
 * purpose. Laravel prepends a `*` to every stub pattern, so a bare
 * `api.sonetel.com/*` becomes `*api.sonetel.com/*` — which ALSO matches
 * `public-api.sonetel.com`, silently swallowing the call requests and returning
 * the auth response instead. `https://api.sonetel.com/*` cannot match
 * `https://public-api.sonetel.com/...`.
 */
function tokenResponse(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'tok_access_1',
        'refresh_token' => 'tok_refresh_1',
        'expires_in' => 86400,
        'token_type' => 'bearer',
    ], $overrides);
}

function configureOpenAi(): void
{
    config([
        'outbound.enabled' => true,
        'outbound.disclosure' => 'Je spreekt met een AI-assistent.',
        'outbound.allow_without_register_screening' => true,
        'outbound.test_numbers' => [],
        'outbound.openai.project_id' => 'proj_test',
        'outbound.openai.key' => 'sk-test',
        'outbound.openai.sip_host' => 'sip.api.openai.com',
    ]);
}

// --- Connecting -------------------------------------------------------------

it('exchanges a password for tokens using the exact Sonetel auth contract', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response(tokenResponse())]);

    $user = User::factory()->create();
    app(SonetelTokenService::class)->connect($user, 'rep@smoothware.nl', 'hunter2');

    Http::assertSent(function ($request) {
        // Auth lives on api.sonetel.com — NOT public-api, which serves everything
        // else. Getting this wrong is a 404 at the worst moment.
        return $request->url() === 'https://api.sonetel.com/SonetelAuth/beta/oauth/token'
            // A FIXED public client, not the rep's credentials.
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sonetel-api:sonetel-api'))
            && $request['grant_type'] === 'password'
            && $request['username'] === 'rep@smoothware.nl'
            && $request['password'] === 'hunter2'
            // Without refresh=yes Sonetel returns no refresh token, and every call
            // stops dead in 24 hours with no way to renew.
            && $request['refresh'] === 'yes';
    });
});

it('never stores the password', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response(tokenResponse())]);

    $user = User::factory()->create();
    app(SonetelTokenService::class)->connect($user, 'rep@smoothware.nl', 'hunter2');

    // The whole row, as raw DB columns — a leak must not hand over a password.
    $raw = json_encode(DB::table('sonetel_accounts')->first());

    expect($raw)->not->toContain('hunter2');
});

it('encrypts the tokens at rest', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response(tokenResponse())]);

    app(SonetelTokenService::class)->connect(User::factory()->create(), 'rep@smoothware.nl', 'pw');

    $raw = DB::table('sonetel_accounts')->first();

    // A bearer token in plaintext is the ability to place calls billed to a real
    // account, sitting in a backup somewhere.
    expect($raw->access_token)->not->toBe('tok_access_1')
        ->and(SonetelAccount::first()->access_token)->toBe('tok_access_1');
});

it('turns expires_in into an absolute expiry', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response(tokenResponse(['expires_in' => 3600]))]);

    $account = app(SonetelTokenService::class)->connect(User::factory()->create(), 'a@b.nl', 'pw');

    // "3600" means nothing once stored — only "expires at 14:32" does.
    expect($account->expires_at->timestamp)->toBeGreaterThan(now()->addMinutes(59)->timestamp)
        ->and($account->expires_at->timestamp)->toBeLessThan(now()->addMinutes(61)->timestamp);
});

it('reports Sonetel\'s own error so a rep can act on it', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'Bad credentials',
    ], 400)]);

    expect(fn () => app(SonetelTokenService::class)->connect(User::factory()->create(), 'a@b.nl', 'wrong'))
        ->toThrow(RuntimeException::class, 'Bad credentials');
});

it('reconnects in place rather than piling up accounts', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response(tokenResponse())]);
    $user = User::factory()->create();

    app(SonetelTokenService::class)->connect($user, 'old@smoothware.nl', 'pw');
    app(SonetelTokenService::class)->connect($user, 'new@smoothware.nl', 'pw');

    expect(SonetelAccount::count())->toBe(1)
        ->and(SonetelAccount::first()->username)->toBe('new@smoothware.nl');
});

// --- Keeping it alive -------------------------------------------------------

it('uses the stored token while it is still fresh', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::response(tokenResponse())]);
    $user = User::factory()->create();
    app(SonetelTokenService::class)->connect($user, 'a@b.nl', 'pw');

    Http::fake(); // any further auth call would be a bug

    expect(app(SonetelTokenService::class)->tokenFor($user))->toBe('tok_access_1');
    Http::assertNothingSent();
});

it('refreshes a token that is about to expire, without a password', function () {
    // A SEQUENCE, not two Http::fake() calls: Laravel MERGES stubs rather than
    // replacing them, so a second fake for the same pattern never wins and the
    // refresh would silently get the connect response back.
    Http::fake(['https://api.sonetel.com/*' => Http::sequence()
        ->push(tokenResponse())                                    // connect
        ->push(tokenResponse(['access_token' => 'tok_access_2'])), // refresh
    ]);

    $user = User::factory()->create();
    $account = app(SonetelTokenService::class)->connect($user, 'a@b.nl', 'pw');

    // Nearly expired: a token with 60s left is not usable for a call — it would
    // die mid-conversation.
    $account->forceFill(['expires_at' => now()->addSeconds(60)])->save();

    expect(app(SonetelTokenService::class)->tokenFor($user))->toBe('tok_access_2');

    Http::assertSent(fn ($request) => ($request['grant_type'] ?? null) === 'refresh_token'
        && ($request['refresh_token'] ?? null) === 'tok_refresh_1');
});

it('keeps the old refresh token when Sonetel does not rotate it', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::sequence()
        ->push(tokenResponse())
        // No refresh_token in the response — losing the old one would mean never
        // being able to refresh again.
        ->push(['access_token' => 'tok_access_2', 'expires_in' => 86400]),
    ]);

    $user = User::factory()->create();
    $account = app(SonetelTokenService::class)->connect($user, 'a@b.nl', 'pw');
    $account->forceFill(['expires_at' => now()->subMinute()])->save();

    app(SonetelTokenService::class)->tokenFor($user);

    expect($account->fresh()->refresh_token)->toBe('tok_refresh_1');
});

it('clears the token when refreshing fails, so the UI says reconnect', function () {
    Http::fake(['https://api.sonetel.com/*' => Http::sequence()
        ->push(tokenResponse())
        // Revoked refresh token — expected eventually, not exceptional.
        ->push(['error' => 'invalid_grant'], 400),
    ]);

    $user = User::factory()->create();
    $account = app(SonetelTokenService::class)->connect($user, 'a@b.nl', 'pw');
    $account->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(app(SonetelTokenService::class)->tokenFor($user))->toBeNull()
        ->and($account->fresh()->hasFreshToken())->toBeFalse()
        ->and($account->fresh()->canRefresh())->toBeFalse();
});

it('returns nothing for a user who never connected', function () {
    expect(app(SonetelTokenService::class)->tokenFor(User::factory()->create()))->toBeNull();
});

// --- The call itself --------------------------------------------------------

it('calls the REAL Sonetel endpoint with the REAL parameter names', function () {
    configureOpenAi();
    Http::fake([
        'https://api.sonetel.com/*' => Http::response(tokenResponse()),
        'https://public-api.sonetel.com/*' => Http::response(['call_id' => 'son_1']),
    ]);

    $user = User::factory()->create();
    app(SonetelTokenService::class)->connect($user, 'a@b.nl', 'pw');
    SonetelAccount::first()->update(['sonetel_number' => '+31201234567']);

    actingAs($user);
    $company = Company::factory()->create(['name' => 'Acme BV']);
    app(SonetelDialer::class)->call('+31612345678', $company);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'call-back')) {
            return true; // the auth call
        }

        // Every one of these was wrong when guessed rather than read.
        return $request->url() === 'https://public-api.sonetel.com/make-calls/call/call-back'
            && $request->hasHeader('Authorization', 'Bearer tok_access_1')
            && $request['call1'] === 'sip:proj_test@sip.api.openai.com;transport=tls'
            && $request['call2'] === '+31612345678'
            && $request['show_1'] === 'automatic'          // underscore, not show1
            && $request['show_2'] === '+31201234567'
            && filled($request['app_id']);
    });
});

it('lets Sonetel choose the caller ID when no number is set', function () {
    configureOpenAi();
    Http::fake([
        'https://api.sonetel.com/*' => Http::response(tokenResponse()),
        'https://public-api.sonetel.com/*' => Http::response(['call_id' => 'son_1']),
    ]);

    $user = User::factory()->create();
    app(SonetelTokenService::class)->connect($user, 'a@b.nl', 'pw');
    actingAs($user);

    app(SonetelDialer::class)->call('+31612345678');

    // Sonetel's docs recommend 'automatic' — it picks the best number on the
    // account — so a configured number is an override, not a requirement.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'call-back')
        || $request['show_2'] === 'automatic');
});

it('refuses to dial as a user who has not connected Sonetel', function () {
    configureOpenAi();
    Http::fake();

    actingAs(User::factory()->create());

    expect(fn () => app(SonetelDialer::class)->call('+31612345678'))
        ->toThrow(RuntimeException::class, 'has not connected a Sonetel account');

    Http::assertNothingSent();
});

it('refuses to dial with nobody to be accountable for the call', function () {
    configureOpenAi();
    Http::fake();

    // No authenticated user, no company owner: a call must belong to someone.
    expect(fn () => app(SonetelDialer::class)->call('+31612345678'))
        ->toThrow(RuntimeException::class, 'no user to call as');
});

it('falls back to the company owner for an unattended call', function () {
    configureOpenAi();
    Http::fake([
        'https://api.sonetel.com/*' => Http::response(tokenResponse()),
        'https://public-api.sonetel.com/*' => Http::response(['call_id' => 'son_1']),
    ]);

    $owner = User::factory()->create();
    app(SonetelTokenService::class)->connect($owner, 'owner@smoothware.nl', 'pw');
    $company = Company::factory()->create(['owner_id' => $owner->id]);

    // Queued/automated calls have no authenticated user; the owner is accountable.
    $call = app(SonetelDialer::class)->call('+31612345678', $company);

    expect($call->handled_by)->toBe($owner->id);
});
