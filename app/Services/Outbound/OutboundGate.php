<?php

namespace App\Services\Outbound;

use App\Enums\SuppressionType;
use App\Models\Call;
use App\Services\SuppressionList;

/**
 * The single place that decides whether the system may dial a number (Phase 6).
 *
 * Every gate FAILS CLOSED. A missing answer is a "no", never a "probably fine" —
 * because the cost is asymmetric: refusing a call we could have made costs one
 * call, while making a call we should not have made costs a regulator's
 * attention and a stranger's afternoon.
 *
 * The reasons are returned as text rather than a bare bool so the refusal can be
 * shown to the human who clicked, logged, and understood later. "It didn't call"
 * with no reason is how people start disabling safety checks.
 */
class OutboundGate
{
    public function __construct(private SuppressionList $suppressions) {}

    /** @return array<int, string> every reason this number must not be dialled */
    public function blockers(string $phone, ?string $email = null, ?string $domain = null): array
    {
        $blockers = [];

        if (! config('outbound.enabled')) {
            $blockers[] = 'Outbound calling is disabled (OUTBOUND_ENABLED=false). '
                .'See GO-LIVE-LEGAL.md item #3 before enabling.';
        }

        // The absolute one. Art. 21(2): no balancing, no exceptions, no override.
        if ($this->suppressions->isSuppressed(phone: $phone, email: $email, domain: $domain)) {
            $blockers[] = 'This number is on the do-not-contact list. The right to object is absolute.';
        }

        if (blank(config('outbound.disclosure'))) {
            $blockers[] = 'No AI disclosure configured. EU AI Act Art. 50 requires telling a '
                .'person they are speaking to a machine.';
        }

        // "none" means no screener is implemented — not "screening skipped".
        if (config('outbound.register_screening') === 'none'
            && ! config('outbound.allow_without_register_screening')) {
            $blockers[] = 'No opt-out register screening is implemented (recht van verzet). '
                .'Set OUTBOUND_ALLOW_WITHOUT_REGISTER_SCREENING=true only if a human has '
                .'taken responsibility for screening by other means.';
        }

        if (! $this->isAllowedNumber($phone)) {
            $blockers[] = 'OUTBOUND_TEST_NUMBERS is set, so only those numbers may be dialled. '
                .'This is the safe way to test: the dialler cannot reach a stranger by mistake.';
        }

        if ($this->callsToday() >= (int) config('outbound.max_calls_per_day', 50)) {
            $blockers[] = 'Daily outbound cap reached ('.config('outbound.max_calls_per_day').').';
        }

        foreach (['openai.project_id', 'openai.key', 'sonetel.token', 'sonetel.caller_id'] as $key) {
            if (blank(config("outbound.{$key}"))) {
                $blockers[] = "Missing configuration: outbound.{$key}";
            }
        }

        return $blockers;
    }

    public function allows(string $phone, ?string $email = null, ?string $domain = null): bool
    {
        return $this->blockers($phone, $email, $domain) === [];
    }

    /**
     * When OUTBOUND_TEST_NUMBERS is set the dialler becomes an allow-list: only
     * those numbers, nothing else. Empty means the list is not in use.
     */
    private function isAllowedNumber(string $phone): bool
    {
        $allowed = (array) config('outbound.test_numbers', []);

        if ($allowed === []) {
            return true;
        }

        $normalize = fn (string $n): ?string => $this->suppressions->normalize(SuppressionType::Phone, $n);
        $target = $normalize($phone);

        foreach ($allowed as $candidate) {
            if ($target !== null && $normalize(trim($candidate)) === $target) {
                return true;
            }
        }

        return false;
    }

    private function callsToday(): int
    {
        return Call::query()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}
