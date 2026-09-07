<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Compensation\Services\IncomeEligibilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One distributor's repurchase obligation for a single monthly cycle.
 *
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $cycle_start_date
 * @property Carbon $due_date
 * @property int $required_bv_paise
 * @property int $completed_bv_paise
 * @property int|null $wallet_balance_paise
 * @property bool|null $wallet_zeroed
 * @property string $status
 * @property Carbon|null $fulfilled_on
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 * @property Carbon|null $resolved_at
 */
final class RepurchaseCycle extends Model
{
    /** Within the cycle window, obligation not yet met but not yet due. */
    public const STATUS_ACTIVE = 'active';

    /** The window closed with an unmet obligation — every day since is forfeited. */
    public const STATUS_SUSPENDED = 'suspended';

    /** Obligation met for the cycle — fully income-eligible. */
    public const STATUS_COMPLETED = 'completed';

    /** Self-purchase BV in the window fell short of the obligation. */
    public const REASON_BV_SHORT = 'bv_short';

    /** BV was met but the repurchase wallet was not ₹0 on the window's last day. */
    public const REASON_WALLET_NONZERO = 'wallet_nonzero';

    /** Both conditions failed. */
    public const REASON_BOTH = 'both';

    protected $fillable = [
        'distributor_id',
        'cycle_start_date',
        'due_date',
        'required_bv_paise',
        'completed_bv_paise',
        'wallet_balance_paise',
        'wallet_zeroed',
        'status',
        'fulfilled_on',
        'failure_reason',
        'completed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start_date' => 'date',
            'due_date' => 'date',
            'required_bv_paise' => 'integer',
            'completed_bv_paise' => 'integer',
            'wallet_balance_paise' => 'integer',
            'wallet_zeroed' => 'boolean',
            'fulfilled_on' => 'date',
            'completed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** Condition 4(A): the window's self-purchase BV cleared the obligation. */
    public function bvMet(): bool
    {
        return $this->completed_bv_paise >= $this->required_bv_paise;
    }

    /**
     * Whether the cycle was fulfilled inside its own window. A cycle fulfilled
     * later forfeited the days in between, which is what {@see IncomeEligibilityService::verdictAsOf()}
     * needs in order to answer for a date in that gap.
     */
    public function fulfilledOnTime(): bool
    {
        return $this->fulfilled_on !== null
            && $this->fulfilled_on->lessThanOrEqualTo($this->due_date);
    }

    /**
     * The days this cycle forfeited: from the day after the due date up to the
     * day before the fulfilment day (client spec §1 — there is no grace, and
     * §2 — "he permanently lost the Business Volume associated with those
     * days"). The single source of truth for "was this distributor failed on
     * day d?"; every consumer reads it rather than re-deriving the arithmetic.
     *
     * Null when nothing is forfeited: fulfilled on time, still inside its own
     * window where the verdict has not been taken yet, or fulfilled on the very
     * next day — the fulfilment day counts in full, so `due + 1` leaves an empty
     * range and an empty range is no window at all (never an inverted one, which
     * a `between()` check would read as the due and fulfilment days being lost).
     * The second element is null while the cycle is still unfulfilled — the
     * window has no end yet.
     *
     * @return array{0: Carbon, 1: Carbon|null}|null
     */
    public function forfeitedWindow(): ?array
    {
        if ($this->fulfilledOnTime()) {
            return null;
        }

        if ($this->resolved_at === null) {
            return null;
        }

        $from = $this->due_date->copy()->startOfDay()->addDay();
        $to = $this->fulfilled_on?->copy()->startOfDay()->subDay();

        if ($to !== null && $to->lessThan($from)) {
            return null;
        }

        return [$from, $to];
    }
}
