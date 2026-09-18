<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Inventory\Services\ReservationAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring `inventory_levels.reserved` back to what the open orders say.
 *
 * The repair half of invariant 2 in `inventory:verify`. A reservation that was
 * raised and never released cannot be replayed away — `reserved` is a counter,
 * not a ledger projection — so the only way back is to recompute it from the
 * orders that can still be holding one and write that.
 *
 * Reports by default; writes only with `--apply`. This is the one command in
 * the module that edits a count rather than appending a movement, so it says
 * exactly what it will change before it changes anything, and every correction
 * lands in `audit_log`.
 */
final class ReconcileReservationsCommand extends Command
{
    protected $signature = 'inventory:reconcile-reservations
        {--apply : Write the corrections. Without it the command only reports.}';

    protected $description = 'Recompute inventory_levels.reserved from the orders that still hold a reservation.';

    public function handle(ReservationAudit $reservations): int
    {
        $drift = $reservations->drift();

        if ($drift === []) {
            $this->components->info('Every reservation is accounted for by an open order.');

            return self::SUCCESS;
        }

        $this->table(
            ['Variant', 'reserved now', 'Open orders', 'Correction'],
            array_map(static fn (array $row): array => [
                $row['variant_id'],
                $row['reserved'],
                $row['expected'],
                sprintf('%+d', $row['drift']),
            ], $drift),
        );

        if (! $this->option('apply')) {
            $this->components->warn(count($drift).' variant(s) would be corrected. Re-run with --apply to write it.');

            return self::SUCCESS;
        }

        $written = DB::transaction(fn (): int => $this->apply($drift));

        $this->components->info($written.' inventory level(s) corrected.');

        return self::SUCCESS;
    }

    /**
     * Write the corrected counts.
     *
     * The variant's lowest-id level row is the one `ProductVariant::inventory`
     * resolves, so it is where checkout and cancel have been writing; it takes
     * the whole expected count. Any other level row for that variant is
     * unreachable to those writes, so whatever it holds is drift by
     * definition and goes to zero.
     *
     * @param  list<array{variant_id: int, reserved: int, expected: int, drift: int}>  $drift
     */
    private function apply(array $drift): int
    {
        $written = 0;

        foreach ($drift as $row) {
            $levels = InventoryLevel::query()
                ->where('product_variant_id', $row['variant_id'])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($levels->isEmpty()) {
                continue;
            }

            foreach ($levels as $index => $level) {
                $target = $index === 0 ? $row['expected'] : 0;
                if ((int) $level->reserved === $target) {
                    continue;
                }

                $level->update(['reserved' => $target]);
                $written++;
            }

            AuditLog::create([
                'actor_id' => null,
                'action' => 'inventory.reservation_reconciled',
                'subject_type' => 'product_variant',
                'subject_id' => $row['variant_id'],
                'before_hash' => AuditLog::digest((string) $row['reserved']),
                'after_hash' => AuditLog::digest((string) $row['expected']),
                'details' => [
                    'reserved_before' => $row['reserved'],
                    'reserved_after' => $row['expected'],
                    'source' => 'inventory:reconcile-reservations',
                ],
            ]);
        }

        return $written;
    }
}
