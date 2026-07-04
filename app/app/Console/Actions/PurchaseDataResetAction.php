<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-data-only reset: wipes every table derived from product sales
 * (orders, BV, GSB/MB and the other bonus results, wallet ledger, payout
 * batches, money ledger, returns) so GSB/MSB can be tested from a clean
 * slate — while PRESERVING everything that is not purchase-derived:
 * users, distributors, the Genos tree, sponsorship, KYC, consents,
 * settings, plan configuration (gsb_slabs, rank_tiers, fortune levels/
 * tiers, lifetime_award_rewards), arete centers, the product catalog,
 * coupons, customers, the ledger chart of accounts, and the audit log.
 *
 * Derived counters are reset rather than deleted: coupons.used_count → 0,
 * inventory_levels.reserved → 0 (on_hand untouched), and
 * distributors.gsb_frozen_at → NULL. Personal-purchase titles need no
 * reset — they are computed from bv_ledger_entries, which is wiped.
 *
 * Idempotent: running twice yields the identical post-state.
 */
final class PurchaseDataResetAction
{
    /**
     * Tables wiped in FK-safe order (children before parents). Anything
     * not listed here is left alone.
     *
     * @var list<string>
     */
    public const WIPE_TABLES = [
        // Compensation — engine results, ledgers, payouts
        'bv_propagation_log',
        'payout_line_items',
        'payout_batches',
        'wallet_ledger_entries',
        'mentorship_bonus_results',
        'gsb_cutoff_results',
        'gsb_carryforward',
        'group_bv_daily',
        'gbb_monthly_results',
        'rank_bonus_results',
        'rank_qualifications',
        'lifetime_award_milestones',
        'fortune_bonus_results',
        'fortune_bonus_participants',
        'adc_bonus_results',
        'repurchase_cycles',
        // Commerce — the purchase flow and its BV trail
        'bv_ledger_entries',
        'coupon_redemptions',
        'return_inspections',
        'buyback_decisions',
        'return_requests',
        'refund_intents',
        'payment_intents',
        'shipments',
        'ledger_entries',
        'ledger_tx',
        'order_cooling_off',
        'order_items',
        'orders',
        'cart_items',
        'carts',
        'shared_carts',
    ];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  Closure(string): void|null  $progress  optional callback for CLI output
     */
    public function execute(?Closure $progress = null): void
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $log('Truncating purchase-derived tables...');
        $this->wipeTables($log);

        $log('Resetting derived counters (gsb_frozen_at, coupons.used_count, inventory reserved)...');
        $this->resetDerivedColumns();

        $log('Removing queued BV-propagation jobs...');
        $deleted = $this->db->table('jobs')
            ->where('payload', 'like', '%PropagateGroupBvJob%')
            ->delete();
        $log(sprintf('  %d queued job(s) removed', $deleted));

        $log('Writing platform.purchase_reset audit-log entry...');
        AuditLog::create([
            'actor_id' => $this->resolveAdminUserId(),
            'action' => 'platform.purchase_reset',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => [
                'tables_wiped' => self::WIPE_TABLES,
                'note' => 'Purchase + compensation data reset via php artisan platform:reset-purchases',
            ],
        ]);

        $log('Purchase-data reset complete.');
    }

    /** @param Closure(string): void $log */
    private function wipeTables(Closure $log): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::WIPE_TABLES as $table) {
                if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                $count = $this->db->table($table)->count();
                $this->db->table($table)->truncate();
                $log(sprintf('  %s: %d row(s) removed', $table, $count));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function resetDerivedColumns(): void
    {
        $this->db->table('distributors')
            ->whereNotNull('gsb_frozen_at')
            ->update(['gsb_frozen_at' => null]);

        $this->db->table('coupons')
            ->where('used_count', '>', 0)
            ->update(['used_count' => 0]);

        $this->db->table('inventory_levels')
            ->where('reserved', '>', 0)
            ->update(['reserved' => 0]);
    }

    private function resolveAdminUserId(): ?int
    {
        return User::query()->where('email', 'admin@arovolife.test')->value('id');
    }
}
