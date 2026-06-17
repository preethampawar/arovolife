<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Single source of truth for "has this distributor entered the commerce flow?".
 *
 * Once a distributor has any product order or BV transaction in their name,
 * repositioning them in the Genos (binary tree) would retroactively shift BV /
 * commission attribution for everyone whose volume was computed against the
 * existing tree. So a line-change must be blocked at the first commerce event
 * (see the Genealogy line-change guard). This service is also surfaced to admins
 * so they can see, at a glance, why a distributor is/isn't line-change eligible.
 */
final class DistributorCommerceActivity
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Per-signal breakdown of a distributor's commerce footprint.
     *
     * - orders: orders attributed to them OR placed by a Customer linked to them.
     * - bv_entries: any row in the BV ledger for them.
     *
     * @return array{orders: int, bv_entries: int, total: int}
     */
    public function summary(int $distributorId): array
    {
        $customerIds = $this->db->table('customers')
            ->where('distributor_id', $distributorId)
            ->pluck('id')
            ->all();

        $orders = $this->db->table('orders')
            ->where(function ($q) use ($distributorId, $customerIds): void {
                $q->where('attributed_distributor_id', $distributorId);
                if ($customerIds !== []) {
                    $q->orWhereIn('customer_id', $customerIds);
                }
            })
            ->count();

        $bvEntries = $this->db->table('bv_ledger_entries')
            ->where('distributor_id', $distributorId)
            ->count();

        return [
            'orders' => $orders,
            'bv_entries' => $bvEntries,
            'total' => $orders + $bvEntries,
        ];
    }

    /** True when the distributor has any commerce activity in their name. */
    public function has(int $distributorId): bool
    {
        return $this->summary($distributorId)['total'] > 0;
    }
}
