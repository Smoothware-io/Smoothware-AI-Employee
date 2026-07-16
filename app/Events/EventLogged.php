<?php

namespace App\Events;

use App\Models\Event;
use App\Services\EventLogger;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces that a row was written to the universal event log.
 *
 * This exists to keep the append-only backbone DUMB. {@see EventLogger}
 * should never know that follow-up automation (Phase 7) — or anything else —
 * wants to react to events; it just says "I logged this". Listeners subscribe.
 *
 * Note the name: this is a Laravel event ABOUT our domain Event model, which is
 * an unavoidable collision of vocabulary. `App\Events\EventLogged` carries an
 * `App\Models\Event`.
 */
class EventLogged
{
    use Dispatchable;

    public function __construct(public readonly Event $event) {}
}
