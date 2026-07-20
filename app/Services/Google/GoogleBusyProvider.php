<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Busy periods from every connected Google Calendar, as plain periods the
 * availability calculator can subtract.
 *
 * Cached briefly. get_available_times runs mid-call and the model may call it
 * more than once in a conversation; without a cache that is a fresh round trip
 * to Google each time, with a human listening to silence.
 *
 * FAILS OPEN, deliberately, and this is the significant judgement here. If
 * Google is unreachable we return no busy periods, which means the AI may offer
 * a time the rep is actually busy. The alternative — refusing to offer anything
 * — turns a Google outage into "the AI can no longer book meetings at all",
 * which is a worse and much more visible failure. The CRM's own appointments
 * still prevent double-booking inside the system; what is lost is only the
 * external calendar, and the rep can decline the invite.
 */
class GoogleBusyProvider
{
    public function __construct(private GoogleCalendarClient $client) {}

    /**
     * @return array<int, object{starts_at: Carbon, ends_at: Carbon}>
     */
    public function busy(Carbon $from, Carbon $until, ?int $userId = null): array
    {
        if (blank(config('services.google.client_id'))) {
            return [];
        }

        $accounts = GoogleCalendarAccount::query()
            ->where('block_from_busy', true)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $periods = [];

        foreach ($accounts as $account) {
            foreach ($this->cachedBusy($account, $from, $until) as $period) {
                $periods[] = (object) $period;
            }
        }

        return $periods;
    }

    /**
     * @return array<int, array{starts_at: Carbon, ends_at: Carbon}>
     */
    private function cachedBusy(GoogleCalendarAccount $account, Carbon $from, Carbon $until): array
    {
        $ttl = (int) config('services.google.busy_cache_seconds', 60);

        // The window is part of the key: two calls asking about different
        // fortnights are different questions and must not share an answer.
        $key = sprintf(
            'google-busy:%d:%s:%s',
            $account->getKey(),
            $from->format('YmdH'),
            $until->format('YmdH'),
        );

        $cached = Cache::get($key);

        if ($cached !== null) {
            return array_map(fn (array $p): array => [
                'starts_at' => Carbon::parse($p['starts_at']),
                'ends_at' => Carbon::parse($p['ends_at']),
            ], $cached);
        }

        $busy = $this->client->busyPeriods($account, $from, $until);

        Cache::put($key, array_map(fn (array $p): array => [
            'starts_at' => $p['starts_at']->toIso8601String(),
            'ends_at' => $p['ends_at']->toIso8601String(),
        ], $busy), $ttl);

        if ($busy !== []) {
            $account->forceFill(['last_synced_at' => now()])->saveQuietly();
        }

        return $busy;
    }
}
