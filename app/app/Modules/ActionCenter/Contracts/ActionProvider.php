<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Contracts;

use App\Modules\ActionCenter\Support\ActionItem;
use Illuminate\Support\Collection;

/**
 * A read-only view over state some other module already owns (plan §1).
 *
 * Rules every implementation must keep:
 *  1. `count()` is one indexed query — no eager loads, no model hydration.
 *  2. Snoozed subjects are excluded (call `AbstractProvider::excludeSnoozed()`).
 *  3. A provider never writes. The only write in the module is a snooze row.
 *  4. A provider whose feature flag is off returns `enabled() === false`, is
 *     hidden from the registry and never queried.
 */
interface ActionProvider
{
    /** Stable key used in routes and snooze rows, e.g. `orders.paid_not_packed`. */
    public function key(): string;

    /** One of the ActionGroup constants. */
    public function group(): string;

    public function label(): string;

    /** One sentence: what the admin should do about it. */
    public function description(): string;

    /** The permission that may act on this type, e.g. `commerce.order.manage`. */
    public function permission(): string;

    /** The type's severity ceiling; individual items may be promoted past its SLA. */
    public function severity(): string;

    /** True = statutory clock; refuses to be snoozed (plan §5). */
    public function statutory(): bool;

    /** Hours from `occurredAt` to `dueAt`; null = backlog with no clock. */
    public function slaHours(): ?int;

    /** Whether this provider's module/feature flag is on. Off = hidden, never queried. */
    public function enabled(): bool;

    /** Cheap COUNT over the same condition as items(). */
    public function count(): int;

    /**
     * The items, most overdue / oldest first.
     *
     * @return Collection<int, ActionItem>
     */
    public function items(int $limit = 50): Collection;

    /** Named route of the existing screen this type routes to, if any. */
    public function targetRoute(): ?string;
}
