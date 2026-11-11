<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use Illuminate\Support\Carbon;

/**
 * The Lifetime Awards one month's Rank Bonus close will create, computed
 * read-only before the Rank Bonus credit transaction opens (R2), so a
 * problem with an award stops the run before any write rather than rolling
 * Rank Bonus credits back.
 */
final readonly class LifetimeAwardPlan
{
    /**
     * @param  list<array{distributor_id: int, rank_number: int, amount_paise: int, rank_qualification_id: int, personal_bv_paise: int, left_genos_bv_paise: int|null, right_genos_bv_paise: int|null}>  $awards
     */
    public function __construct(
        public Carbon $month,
        public array $awards,
    ) {}

    public static function none(Carbon $month): self
    {
        return new self($month, []);
    }
}
