<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the /api/voice/* endpoints. These are called by go-voice, server to
 * server, with the shared token — a tool call can write to the CRM and book a
 * meeting, so an unauthenticated one is a stranger acting as the AI.
 *
 * Fails closed: if no token is configured, NOTHING is authorised. An endpoint
 * that writes to the CRM must never be reachable because someone forgot to set a
 * secret.
 */
class VerifyVoiceServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('voice.service_token');

        if ($expected === '') {
            abort(503, 'voice service token not configured');
        }

        $presented = (string) $request->bearerToken();

        // hash_equals: a token check with == leaks length and content through
        // timing. Small channel, free to close.
        if ($presented === '' || ! hash_equals($expected, $presented)) {
            abort(401, 'invalid service token');
        }

        return $next($request);
    }
}
