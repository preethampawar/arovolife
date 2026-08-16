<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tunable parameters for the agreement's §21 dormancy termination.
 *
 * The twelve-month window and the seven-day notice are terms of the Direct
 * Seller Agreement, not preferences: they live in settings so a change is an
 * audited edit that can be reconciled against a §16.2 amendment notice, not a
 * silent deploy.
 *
 * ⚠ The re-registration wait is expressed against the CURRENT rank ladder
 * (1 Silver Partner … 9 Elite Diamond Partner). §21 was drafted against an
 * older ladder that named "Sales Master" and "Diamond Master", neither of
 * which exists today. The mapping used here — any rank below the Diamond
 * tiers waits one year, Diamond Partner (rank 5) and above wait two — is the
 * closest faithful reading, but it is an interpretation and the Product Owner
 * should confirm it before launch.
 */
final class ComplianceTerminationSettings
{
    private const SCALAR_DEFAULTS = [
        // §21 — "no sale for continuous 12 months from agreement or last sale".
        'termination.inactivity_months' => 12,
        // §21 — "7-day written notice" before the account closes.
        'termination.notice_days' => 7,
        // §21 re-registration wait, in years.
        'termination.reregistration_wait_years.ranked' => 1,
        'termination.reregistration_wait_years.diamond' => 2,
        // The rank at which the longer wait begins (5 = Diamond Partner).
        'termination.diamond_rank_threshold' => 5,
        // Master switch. OFF means the sweep observes and reports but never
        // issues a notice or terminates — the posture to launch in, until the
        // first live cohort of dormant accounts has been reviewed by hand.
        'termination.inactivity_sweep_enabled' => false,
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function inactivityMonths(): int
    {
        return $this->scalarInt('termination.inactivity_months');
    }

    public function noticeDays(): int
    {
        return $this->scalarInt('termination.notice_days');
    }

    public function sweepEnabled(): bool
    {
        return $this->scalarBool('termination.inactivity_sweep_enabled');
    }

    public function diamondRankThreshold(): int
    {
        return $this->scalarInt('termination.diamond_rank_threshold');
    }

    /**
     * Years the PAN must wait before it may hold an account again.
     *
     * An unranked seller waits nothing: they never held a rank, and §21's wait
     * exists to stop a ranked seller from abandoning a position and rebuilding
     * a better one straight away.
     */
    public function reregistrationWaitYears(int $highestRankAchieved): int
    {
        if ($highestRankAchieved <= 0) {
            return 0;
        }

        return $highestRankAchieved >= $this->diamondRankThreshold()
            ? $this->scalarInt('termination.reregistration_wait_years.diamond')
            : $this->scalarInt('termination.reregistration_wait_years.ranked');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function scalarInt(string $key): int
    {
        $value = $this->scalar($key);

        return $value !== null ? (int) $value : (int) (self::SCALAR_DEFAULTS[$key] ?? 0);
    }

    private function scalarBool(string $key): bool
    {
        $value = $this->scalar($key);

        if ($value === null) {
            return (bool) (self::SCALAR_DEFAULTS[$key] ?? false);
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    private function scalar(string $key): ?string
    {
        if ($this->scalarCache === null) {
            $this->scalarCache = DB::table('settings')->pluck('value', 'key')->all();
        }

        $value = $this->scalarCache[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
