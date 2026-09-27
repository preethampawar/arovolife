<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * The carry-forward store as it WOULD stand after a run of pure computations,
 * chained in memory in date order (E5 review N1).
 *
 * A distributor still owed earlier cut-off days has a store that never absorbed
 * them. Reserving tonight's share on that store prices a different day from
 * the one the backfill will later settle, so the full cut-off computes each
 * owed day first — without writing anything — and computes tonight on the
 * store those days would leave behind. The backfill still computes every day
 * fresh; this only makes the reservation match what it will pay.
 */
final readonly class GsbCarryforwardSnapshot
{
    /**
     * @param  list<int>  $consumedTopupOrderIds  personal-BV top-up orders the chained days already spent
     */
    public function __construct(
        public ?string $powerSide,
        public int $powerPaise,
        public int $slab1Paise,
        public array $consumedTopupOrderIds = [],
    ) {}

    /**
     * The store after $computation settles, starting from $prior (null = the
     * real store, untouched so far).
     *
     * PARITY PARTNER: GsbCutoffService::settleMatchable(). No-match and matched
     * (frozen included) write the power CF, the stronger side and the slab-1 CF
     * exactly as below; a forfeited day, a below-minimum day and an already
     * settled day write nothing, so the store is whatever it was before. Any
     * change to what settle writes must be mirrored here, or the reservation
     * and the backfill stop agreeing.
     *
     * Returns null only when nothing has moved the store yet — the next
     * computation then reads the real store, which is what these outcomes left.
     */
    public static function after(GsbCutoffComputation $computation, ?self $prior): ?self
    {
        if (! in_array($computation->outcome, [
            GsbCutoffComputation::OUTCOME_NO_MATCH,
            GsbCutoffComputation::OUTCOME_MATCHED,
        ], true)) {
            return $prior;
        }

        return new self(
            powerSide: $computation->strongerSide,
            powerPaise: $computation->newPowerCf,
            slab1Paise: $computation->newSlab1Cf,
            consumedTopupOrderIds: array_values(array_unique([
                ...($prior->consumedTopupOrderIds ?? []),
                ...$computation->topupOrderIds,
            ])),
        );
    }
}
