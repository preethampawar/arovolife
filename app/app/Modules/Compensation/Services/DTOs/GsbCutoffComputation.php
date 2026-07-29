<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\GsbCutoffResult;
use Illuminate\Support\Carbon;

/**
 * The pure, side-effect-free outcome of one distributor's GSB cut-off
 * computation (pass 1). Produced by GsbCutoffService::computeForDistributor(),
 * priced by price() once the day's pool economics are frozen, and finally
 * settled (all writes: top-up, carry-forward, result row, wallet) by settle().
 *
 * All leg/CF figures are in paise. leftToday/rightToday already include the
 * simulated personal-BV top-up recorded in topupOrderIds — settle() applies
 * exactly those orders so the settled accumulator matches the computation even
 * if new orders land between the two passes.
 */
final class GsbCutoffComputation
{
    public const OUTCOME_ALREADY_SETTLED = 'already_settled';

    public const OUTCOME_BELOW_MIN_BV = 'below_600bv';

    public const OUTCOME_NO_MATCH = 'no_match';

    public const OUTCOME_MATCHED = 'matched';

    /** Rupee-per-score value actually used to price grossPaise (set by price()). */
    public ?int $pricedScoreValuePaise = null;

    /** Final gross for the result row (set by price(); 0 until priced). */
    public int $grossPaise = 0;

    /**
     * @param  list<int>  $topupOrderIds
     */
    public function __construct(
        public readonly int $distributorId,
        public readonly Carbon $date,
        public readonly ?GsbCutoffResult $existing,
        public readonly string $outcome,
        public readonly bool $isFrozen = false,
        public readonly string $eligibility = '',
        public readonly int $personalBvPaise = 0,
        public readonly int $leftToday = 0,
        public readonly int $rightToday = 0,
        public readonly int $weakerTotal = 0,
        public readonly string $strongerSide = 'L',
        public readonly ?string $powerSideBefore = null,
        public readonly int $cfBeforePower = 0,
        public readonly int $cfBeforeSlab1 = 0,
        public readonly int $newPowerCf = 0,
        public readonly int $newSlab1Cf = 0,
        public readonly ?int $slabIndex = null,
        public readonly int $slabThreshold = 0,
        public readonly ?int $slabScore = null,
        public readonly int $slabScoreValuePaise = 0,
        public readonly int $nominalBonusPaise = 0,
        public readonly ?string $topupSide = null,
        public readonly int $topupBvPaise = 0,
        public readonly array $topupOrderIds = [],
    ) {}

    public function isMatched(): bool
    {
        return $this->outcome === self::OUTCOME_MATCHED;
    }

    /**
     * The fixed (pool-independent) gross a matched slab 1–2 computation will
     * pay: score × the slab's fixed score value, falling back to the
     * materialized bonus when the slab carries no score. Used by the daily
     * command to fund the pool before pricing.
     */
    public function fixedSlabGrossPaise(): int
    {
        return $this->slabScore !== null
            ? $this->slabScore * $this->slabScoreValuePaise
            : $this->nominalBonusPaise;
    }
}
