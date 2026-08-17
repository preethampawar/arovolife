<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Actions\PurchaseDataResetAction;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

final class PurchaseDataResetCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'platform:reset-purchases {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Wipe all purchase-derived data (orders, BV, GSB/MB + other bonus results, wallet ledger, payouts, returns) while keeping users, distributors, the Genos tree, KYC, settings, plan configuration and the product catalog.';

    public function handle(PurchaseDataResetAction $action): int
    {
        // --force must not be a production bypass. The wipe now also removes the
        // frozen daily pool economics (gsb_daily_pools / msb_daily_pools), which
        // are the primary evidence for why a past payout was the amount it was.
        if (app()->isProduction() && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('THIS WILL WIPE ALL PURCHASE-DERIVED DATA (orders, BV, bonuses, wallets, payouts).');
            $this->line(sprintf('Database: %s', (string) DB::connection()->getDatabaseName()));
            $this->line('Current row counts:');
            foreach (PurchaseDataResetAction::wipeTables() as $table) {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                $count = DB::table($table)->count();
                if ($count > 0) {
                    $this->line(sprintf('  %s: %d', $table, $count));
                }
            }
            $this->line('Preserved: users, distributors, Genos tree, KYC, consents, settings, plan config (slabs/tiers/levels/rewards), arete centers, catalog, coupons, customers, ledger accounts, audit log.');
            $this->line('');
            if (! $this->confirm('Proceed with purchase-data reset?', false)) {
                $this->info('Aborted.');

                return self::FAILURE;
            }
        }

        $action->execute(function (string $message): void {
            $this->line($message);
        });

        $this->info('platform:reset-purchases complete.');

        return self::SUCCESS;
    }
}
