<?php

namespace App\Filament\Widgets;

use App\Services\Reporting\ProviderStatus;
use Filament\Widgets\Widget;

/**
 * Says out loud that the AI numbers below are not real signal yet (Phase 8).
 *
 * Every AI metric on this dashboard is computed from runs produced by whichever
 * adapter is bound. While those are offline fakes, the numbers describe our stubs
 * — a screenshot of a green "2% fallback rate" taken today would be read as a KPI
 * by someone who wasn't in the room.
 *
 * Driven by {@see ProviderStatus}, which reads the same config keys
 * AppServiceProvider binds on, so this disappears by itself the moment a real
 * provider is wired rather than rotting into a stale banner.
 */
class ProviderStatusBanner extends Widget
{
    protected string $view = 'filament.widgets.provider-status-banner';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100; // above everything it qualifies

    public static function canView(): bool
    {
        return app(ProviderStatus::class)->hasFakes();
    }

    /** @return array<int, string> */
    public function getFakes(): array
    {
        return app(ProviderStatus::class)->fakes();
    }

    public function getIsAllFake(): bool
    {
        return app(ProviderStatus::class)->allFake();
    }
}
