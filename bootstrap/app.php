<?php

use App\Http\Middleware\VerifyVoiceServiceToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In production this runs behind Dokploy's Traefik, which terminates TLS
        // and forwards plain HTTP. Without trusting it, Laravel believes every
        // request is http:// and generates asset URLs to match — which browsers
        // then block as mixed content on an https:// page, and the Filament panel
        // renders with no styling at all.
        //
        // It also fixes $request->ip(), which otherwise logs the proxy's address
        // for every request. The webhook's bad-signature warning records that IP;
        // logging Traefik there would make it useless for spotting an attacker.
        //
        // '*' is safe HERE specifically because the container is never reachable
        // directly: docker-compose.prod.yml uses `expose`, not `ports`, so the
        // only path in is through the proxy. On a directly-reachable app this
        // would let anyone spoof X-Forwarded-For.
        $middleware->trustProxies(at: '*');

        // Server-to-server endpoints that authenticate with a signature or a
        // shared token, not a session CSRF cookie: telephony webhooks and the
        // voice tool API that go-voice calls.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'api/voice/*',
        ]);

        // Guards the voice tool API. Registered as an alias here; applied on the
        // routes in web.php.
        $middleware->alias([
            'voice.token' => VerifyVoiceServiceToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
