<?php

namespace App\Services\Outbound;

use App\Enums\CampaignStatus;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Company;
use App\Services\Availability\AvailabilityCalculator;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Works through a campaign, one call at a time.
 *
 * ONE call per tick, by design. A loop that dials everything it can each minute
 * is a robocall wearing a for-each, and the pacing setting would become a
 * suggestion. The scheduler ticks often; this decides whether the moment has
 * come.
 *
 * Progress is derived from the `calls` table rather than tracked in a join
 * table. There is then exactly one answer to "has this company been called",
 * and it is the same record a human sees in the UI — no second source to drift.
 *
 * Every safety gate still applies. This service decides WHO is next and WHETHER
 * NOW; OutboundGate decides whether dialling is permitted at all, and it is not
 * reimplemented or bypassed here.
 */
class CampaignRunner
{
    public function __construct(
        private AiCallDialer $dialer,
        private AvailabilityCalculator $availability,
    ) {}

    /**
     * Advance every running campaign by at most one call.
     *
     * @return int calls actually placed
     */
    public function tick(): int
    {
        $placed = 0;

        foreach (Campaign::query()->running()->get() as $campaign) {
            if ($this->advance($campaign)) {
                $placed++;
            }
        }

        return $placed;
    }

    /** @return bool whether a call was placed */
    public function advance(Campaign $campaign): bool
    {
        if (! $campaign->status->isDialling()) {
            return false;
        }

        if (! $campaign->isDueToDial()) {
            return false;
        }

        if ($campaign->respect_working_hours && ! $this->withinWorkingHours()) {
            return false;
        }

        $company = $this->nextTarget($campaign);

        if ($company === null) {
            // Nobody left. Say so rather than leaving it "running" forever with
            // nothing happening, which reads as broken.
            $campaign->forceFill([
                'status' => CampaignStatus::Completed,
                'completed_at' => now(),
            ])->save();

            return false;
        }

        try {
            $this->dialer->call(
                phone: (string) $company->phone,
                company: $company,
                objective: $campaign->objective,
                // The campaign's creator is accountable for its calls. A campaign
                // dials while nobody is logged in, so there is no "current user"
                // to fall back on.
                as: $campaign->creator,
            );
        } catch (RuntimeException $e) {
            // A refusal is usually about THIS company (suppressed, no number) and
            // the campaign should carry on. A refusal about the whole system
            // (outbound disabled) will simply refuse again next tick, which is
            // the correct amount of noise.
            Log::warning('campaign: call refused', [
                'campaign' => $campaign->getKey(),
                'company' => $company->getKey(),
                'reason' => $e->getMessage(),
            ]);

            // Marked as attempted so a permanently un-callable company cannot
            // wedge the campaign by being picked again every single tick.
            $this->recordRefusal($campaign, $company, $e->getMessage());
            $campaign->forceFill(['last_dialed_at' => now()])->save();

            return false;
        }

        $campaign->forceFill(['last_dialed_at' => now()])->save();

        return true;
    }

    /**
     * The next company to ring: has a phone number, and has either never been
     * called or was called long enough ago to be worth another try.
     */
    public function nextTarget(Campaign $campaign): ?Company
    {
        $maxAttempts = max(1, $campaign->max_attempts);
        $retryAfter = now()->subHours(max(1, $campaign->retry_after_hours));

        return Company::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            // Never reached again once a real conversation happened. Ringing
            // somebody a second time to say the same thing is how a lead becomes
            // a complaint.
            ->whereDoesntHave('calls', fn ($q) => $q
                ->where('direction', 'outbound')
                ->whereIn('status', ['completed', 'in_progress', 'dialing']))
            ->whereHas('calls', fn ($q) => $q->where('direction', 'outbound'), '<', $maxAttempts)
            // Either never tried, or the retry window has passed.
            ->where(fn ($q) => $q
                ->whereDoesntHave('calls', fn ($c) => $c->where('direction', 'outbound'))
                ->orWhereDoesntHave('calls', fn ($c) => $c
                    ->where('direction', 'outbound')
                    ->where('created_at', '>', $retryAfter)))
            ->orderBy('id')
            ->first();
    }

    /** How much of this campaign is done — for the progress a human reads. */
    public function progress(Campaign $campaign): array
    {
        $total = Company::query()->where('campaign_id', $campaign->getKey())->count();

        $callable = Company::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->count();

        $reached = Company::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereHas('calls', fn ($q) => $q
                ->where('direction', 'outbound')
                ->where('status', 'completed'))
            ->count();

        $attempted = Company::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereHas('calls', fn ($q) => $q->where('direction', 'outbound'))
            ->count();

        return [
            'total' => $total,
            'callable' => $callable,
            'no_phone' => $total - $callable,
            'attempted' => $attempted,
            'reached' => $reached,
            'remaining' => max(0, $callable - $attempted),
        ];
    }

    /**
     * Whether the AI may ring anyone at all right now, using the same working
     * hours the booking tool uses. One definition of "when we work" — a second
     * one would eventually disagree with the first, at 21:00, on someone's phone.
     */
    private function withinWorkingHours(): bool
    {
        return $this->availability->isWithinWorkingHours();
    }

    /**
     * A refused company still counts as an attempt, so it cannot be re-picked on
     * every tick and stall the whole campaign behind one bad row.
     */
    private function recordRefusal(Campaign $campaign, Company $company, string $reason): void
    {
        Call::create([
            'company_id' => $company->getKey(),
            'direction' => 'outbound',
            'status' => 'failed',
            'started_at' => now(),
            'ended_at' => now(),
            'objective' => $campaign->objective,
            'summary' => 'Not dialled: '.$reason,
        ]);
    }
}
