<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\CallContentEraser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retention enforcement (GDPR): destroys the personal content of any call whose
 * retention window has expired, keeping the metadata. The retention period is
 * config-driven (see config/receptionist.php) — a legal decision, not hardcoded.
 * Scheduled daily (routes/console.php).
 */
class PurgeExpiredCallContent implements ShouldQueue
{
    use Queueable;

    public function handle(CallContentEraser $eraser): void
    {
        Call::query()
            ->whereNotNull('retention_expires_at')
            ->where('retention_expires_at', '<', now())
            ->whereNull('content_erased_at')
            ->each(fn (Call $call) => $eraser->erase($call, null, 'retention_expired'));
    }
}
