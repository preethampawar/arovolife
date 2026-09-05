<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\RepurchaseMonthlySnapshot;
use App\Modules\Compensation\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Freezes every distributor's repurchase wallet balance as it stood at the end
 * of a calendar month.
 *
 * The bonus engines gate on "was the repurchase wallet spent down to ₹0 for
 * that month". Answering that from the live balance gives a different verdict
 * every time a month is re-run, so the answer is written once here, on the 1st,
 * and every engine reads the same row afterwards. The row doubles as the audit
 * record of why a distributor was excluded from a month.
 */
final class RepurchaseMonthlySnapshotCommand extends Command
{
    protected $signature = 'compensation:repurchase-snapshot
                            {--date= : Target month end date (YYYY-MM-DD), defaults to the last day of the prior calendar month}';

    protected $description = 'Snapshot every distributor repurchase wallet balance at month end for eligibility gating and audit';

    public function __construct(
        private readonly WalletService $wallet,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('date') !== null) {
            $rawDate = (string) $this->option('date');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $this->error("--date must be in YYYY-MM-DD format, got: {$rawDate}");

                return self::FAILURE;
            }
            $monthEnd = Carbon::createFromFormat('Y-m-d', $rawDate, 'Asia/Kolkata')->endOfDay();
        } else {
            $monthEnd = Carbon::now('Asia/Kolkata')->startOfMonth()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        }

        $cycleMonth = $monthEnd->copy()->startOfMonth()->toDateString();
        $asOf = $monthEnd->copy()->setTimezone('UTC');

        $this->info("Repurchase wallet snapshot — month {$cycleMonth}, as of {$monthEnd->toDateTimeString()} IST");

        // Only distributors the repurchase wallet has ever touched can hold a
        // non-zero balance; everyone else is a ₹0 the gate already fails open on.
        /** @var list<int> $distributorIds */
        $distributorIds = array_values(
            DB::table('wallet_ledger_entries')
                ->whereIn('type', WalletService::REPURCHASE_TYPES)
                ->where('created_at', '<=', $asOf)
                ->distinct()
                ->pluck('distributor_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
        );

        if ($distributorIds === []) {
            $this->info('No repurchase wallet activity — nothing to snapshot.');

            return self::SUCCESS;
        }

        $balances = $this->wallet->repurchaseWalletBalancesAsOfPaise($distributorIds, $asOf);

        $written = 0;

        foreach ($distributorIds as $distributorId) {
            $balancePaise = $balances[$distributorId] ?? 0;

            RepurchaseMonthlySnapshot::updateOrCreate(
                ['distributor_id' => $distributorId, 'cycle_month' => $cycleMonth],
                [
                    'balance_paise' => $balancePaise,
                    'was_zeroed' => $balancePaise === 0,
                    'snapshotted_at' => now(),
                ],
            );

            $written++;
        }

        Log::info('repurchase.snapshot.written', [
            'cycle_month' => $cycleMonth,
            'as_of' => $asOf->toDateTimeString(),
            'snapshots' => $written,
        ]);

        $this->info("Done — snapshots written: {$written}");

        return self::SUCCESS;
    }
}
