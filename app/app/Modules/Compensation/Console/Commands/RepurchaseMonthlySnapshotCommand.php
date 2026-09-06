<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\RepurchaseMonthlySnapshot;
use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
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
 *
 * It takes a MONTH and derives the as-of instant itself. It used to take a
 * `--date`, which every caller filled from a different end of the month — the
 * scheduler and the recompute replay from `EngineDefinition::periodRelativeTo()`
 * ('prev-month' → the month's FIRST day), the monthly close from
 * `MonthlyEngineCompletionGate::periodFor()` (the month's LAST day). Both
 * normalise to the same `cycle_month`, so one August snapshot held the balance
 * as at 1 August and the next held it as at 31 August: on the dev database the
 * population moved 54 → 74 distributors and the frozen total by ₹60,082.38 with
 * no ledger row inserted in between. One month, one as-of instant, one
 * period_start — derived in this one place.
 */
final class RepurchaseMonthlySnapshotCommand extends Command
{
    protected $signature = 'compensation:repurchase-snapshot
                            {--month= : Month to freeze (YYYY-MM), defaults to the calendar month that has just ended}
                            {--force : Discard an existing snapshot for the month and freeze it again}';

    protected $description = 'Freeze every distributor repurchase wallet balance at month end for eligibility gating and audit';

    public function __construct(
        private readonly WalletService $wallet,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $month = $this->resolveMonth();

        if ($month === null) {
            return self::FAILURE;
        }

        // The month's last instant in the application timezone. NOT converted to
        // UTC: `wallet_ledger_entries.created_at` is written through the same
        // app timezone (Asia/Kolkata), so converting shifted the boundary back
        // 5h30m and silently dropped every entry made after 18:30 on the last
        // day of the month.
        $monthEnd = $month->copy()->endOfMonth()->endOfDay();
        $cycleMonth = $month->toDateString();

        $this->info("Repurchase wallet snapshot — month {$cycleMonth}, as of {$monthEnd->toDateTimeString()} IST");

        $frozen = RepurchaseMonthlySnapshot::query()->where('cycle_month', $cycleMonth)->count();

        if ($frozen > 0 && ! $this->replaceExistingFreeze($cycleMonth, $monthEnd, $frozen)) {
            $this->info("Already frozen for {$cycleMonth} — {$frozen} snapshot(s) left untouched.");

            return self::SUCCESS;
        }

        // Only distributors the repurchase wallet has ever touched can hold a
        // non-zero balance; everyone else is a ₹0 the gate already fails open on.
        /** @var list<int> $distributorIds */
        $distributorIds = array_values(
            DB::table('wallet_ledger_entries')
                ->whereIn('type', WalletService::REPURCHASE_TYPES)
                ->where('created_at', '<=', $monthEnd)
                ->distinct()
                ->pluck('distributor_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
        );

        if ($distributorIds === []) {
            $this->info('No repurchase wallet activity — nothing to snapshot.');

            return self::SUCCESS;
        }

        $balances = $this->wallet->repurchaseWalletBalancesAsOfPaise($distributorIds, $monthEnd);

        $written = $this->freeze($distributorIds, $balances, $cycleMonth);

        Log::info('repurchase.snapshot.written', [
            'cycle_month' => $cycleMonth,
            'as_of' => $monthEnd->toDateTimeString(),
            'snapshots' => $written,
        ]);

        $this->info("Done — snapshots written: {$written}");

        return self::SUCCESS;
    }

    /**
     * Write the whole month in one transaction. Atomicity is what lets the
     * "already frozen" guard above be a simple row count: a crash half-way
     * through would otherwise leave a partial month that no later run would
     * ever complete, because the rows already there would read as frozen.
     *
     * @param  list<int>  $distributorIds
     * @param  array<int, int>  $balances  distributor id => paise
     */
    private function freeze(array $distributorIds, array $balances, string $cycleMonth): int
    {
        $now = Carbon::now();

        $rows = [];

        foreach ($distributorIds as $distributorId) {
            $balancePaise = $balances[$distributorId] ?? 0;

            $rows[] = [
                'distributor_id' => $distributorId,
                'cycle_month' => $cycleMonth,
                'balance_paise' => $balancePaise,
                'was_zeroed' => $balancePaise === 0,
                'snapshotted_at' => $now,
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                RepurchaseMonthlySnapshot::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Delete a month's snapshot so the caller can freeze it afresh. Returns true
     * when the rows were removed. The repurchase twin of
     * {@see FortuneBonusService::replacePrematureFreeze()}
     * and {@see GrowthBoosterBonusService::replacePrematureFreeze()}.
     *
     * Two ways back, and nothing else:
     *
     *   • PREMATURE — the freeze was written before the month had closed (a
     *     mid-month manual run, or the recompute tool catching up the period in
     *     flight). It froze balances that still had days left to move, and every
     *     later engine would gate on them. The month is discarded and re-frozen,
     *     exactly as the pool engines do.
     *   • --force — a deliberate operator re-freeze for a month whose input was
     *     itself rebuilt (the recompute tool wipes and replays the wallet
     *     ledger). Audit-logged with the row count and total it discarded,
     *     because it moves the eligibility basis of a closed month.
     *
     * Every other re-run leaves the month exactly as it is.
     */
    private function replaceExistingFreeze(string $cycleMonth, Carbon $monthEnd, int $frozen): bool
    {
        $monthClosedAt = $monthEnd->copy()->addDay()->startOfDay();

        $frozenAt = RepurchaseMonthlySnapshot::query()
            ->where('cycle_month', $cycleMonth)
            ->min('created_at');

        $wasPremature = $frozenAt !== null && Carbon::parse((string) $frozenAt)->lt($monthClosedAt);

        if (! $wasPremature && ! $this->option('force')) {
            return false;
        }

        $details = [
            'cycle_month' => $cycleMonth,
            'reason' => $wasPremature ? 'premature_freeze' : 'forced',
            'frozen_at' => $frozenAt === null ? null : Carbon::parse((string) $frozenAt)->toDateTimeString(),
            'discarded_snapshots' => $frozen,
            'discarded_balance_paise' => (int) RepurchaseMonthlySnapshot::query()
                ->where('cycle_month', $cycleMonth)
                ->sum('balance_paise'),
        ];

        RepurchaseMonthlySnapshot::query()->where('cycle_month', $cycleMonth)->delete();

        Log::warning('repurchase.snapshot.refrozen', $details);

        AuditLog::create([
            'actor_id' => null,
            'action' => 'repurchase.snapshot.refrozen',
            'subject_type' => 'repurchase_monthly_snapshot',
            'subject_id' => 0,
            'details' => $details,
        ]);

        $this->warn(sprintf(
            'Discarded the existing %s snapshot (%d row(s), %s) — freezing the month again.',
            $cycleMonth,
            $frozen,
            $wasPremature ? 'frozen before the month closed' : 'forced re-freeze',
        ));

        return true;
    }

    /** The calendar month that has just ended, or an explicit --month. */
    private function resolveMonth(): ?Carbon
    {
        $raw = $this->option('month');

        if ($raw === null || trim((string) $raw) === '') {
            return Carbon::now('Asia/Kolkata')->startOfMonth()->subMonthNoOverflow()->startOfDay();
        }

        $trimmed = trim((string) $raw);

        if (preg_match('/^\d{4}-\d{2}$/', $trimmed) !== 1) {
            $this->error("--month must be in YYYY-MM format, got: {$trimmed}");

            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $trimmed.'-01')->startOfDay();
    }
}
