<?php

namespace App\Console\Commands;

use App\Services\Outbound\CampaignRunner;
use Illuminate\Console\Command;

/**
 * The heartbeat that makes campaigns work by themselves.
 *
 * Ticks every minute and places AT MOST one call per campaign. The pace lives on
 * the campaign, not here, so slowing a campaign down is something a client does
 * in the UI rather than something we deploy.
 */
class RunCampaigns extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Advance every running campaign by at most one call';

    public function handle(CampaignRunner $runner): int
    {
        $placed = $runner->tick();

        $this->info($placed === 0
            ? 'No calls were due.'
            : "Placed {$placed} call(s).");

        return self::SUCCESS;
    }
}
