<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Whose sales a report is allowed to see.
 *
 * A value object rather than a nullable `?int $distributorId`, because the
 * difference between "no filter" and "I forgot to pass the filter" is the
 * difference between a report and a data leak. SalesReportService *requires*
 * one of these, so a future caller cannot default its way into the whole book.
 *
 * This is the structural backstop the OrderPolicy docblock argues for: the
 * route gate is the first line, and this is what holds if someone adds a
 * controller and forgets it.
 */
final readonly class SalesScope
{
    private function __construct(
        public ?int $distributorId,
        public bool $excludeSelfConsumption,
    ) {}

    /** Every order in the book. Admin reports only. */
    public static function all(): self
    {
        return new self(null, false);
    }

    /**
     * One distributor's own sales.
     *
     * Self-consumption is excluded because a distributor's own purchases are
     * not their sales — the same predicate `MyOrdersController::mySales()`
     * uses, so the two screens cannot drift apart.
     */
    public static function distributor(int $distributorId): self
    {
        return new self($distributorId, true);
    }

    public function isAll(): bool
    {
        return $this->distributorId === null;
    }

    /**
     * Narrow a query to this scope.
     *
     * @template TBuilder of EloquentBuilder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  string  $table  qualify columns so this is safe inside a join
     * @return TBuilder
     */
    public function apply(EloquentBuilder|QueryBuilder $query, string $table = 'orders'): EloquentBuilder|QueryBuilder
    {
        if ($this->distributorId !== null) {
            $query->where("{$table}.attributed_distributor_id", $this->distributorId);
        }

        if ($this->excludeSelfConsumption) {
            $query->where("{$table}.self_consumption", false);
        }

        return $query;
    }
}
