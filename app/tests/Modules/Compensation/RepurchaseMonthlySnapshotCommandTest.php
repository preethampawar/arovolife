<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseMonthlySnapshot;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** One repurchase ledger row at an exact instant, bypassing the service's now(). */
function seedRepurchaseEntry(int $distributorId, string $type, int $amountPaise, string $createdAt): void
{
    static $reference = 0;

    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => $type,
        'amount_paise' => $amountPaise,
        'reference_id' => ++$reference,
        'reference_type' => 'gsb_cutoff_result',
        'created_at' => $createdAt,
    ]);
}

/**
 * @return list<array{int, string, int, bool}>
 */
function augustSnapshotRows(): array
{
    return array_values(
        RepurchaseMonthlySnapshot::query()
            ->orderBy('distributor_id')
            ->get()
            ->map(fn (RepurchaseMonthlySnapshot $row): array => [
                $row->distributor_id,
                $row->cycle_month->toDateString(),
                $row->balance_paise,
                $row->was_zeroed,
            ])
            ->all()
    );
}

it('freezes the month-end balance and is byte-identical on a second run', function (): void {
    $dist = Distributor::factory()->create();

    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 20_000, '2026-08-13 00:10:00');
    seedRepurchaseEntry($dist->id, 'repurchase_wallet_used', -5_000, '2026-08-20 11:00:00');

    expect(Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']))->toBe(0);

    $first = augustSnapshotRows();

    expect($first)->toBe([[$dist->id, '2026-08-01', 15_000, false]]);

    // A closed month is a frozen fact: whatever lands afterwards — including a
    // backdated ledger row, which is exactly what the recompute tool writes —
    // must not move it.
    $late = Distributor::factory()->create();
    seedRepurchaseEntry($late->id, 'repurchase_deduction', 90_000, '2026-08-14 00:10:00');

    expect(Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']))->toBe(0);

    expect(augustSnapshotRows())->toBe($first);
});

it('counts the whole of the last day, not only up to 18:30', function (): void {
    // The as-of instant used to be converted to UTC before being compared with
    // created_at, which is written in the app timezone — lopping 5h30m off the
    // end of every month.
    $dist = Distributor::factory()->create();

    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 40_000, '2026-08-31 22:45:00');
    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 70_000, '2026-09-01 00:10:00');

    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']);

    expect(augustSnapshotRows())->toBe([[$dist->id, '2026-08-01', 40_000, false]]);
});

it('refuses to move a frozen month even when the ledger behind it was rebuilt', function (): void {
    $dist = Distributor::factory()->create();
    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 20_000, '2026-08-13 00:10:00');

    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']);

    DB::table('wallet_ledger_entries')->delete();
    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 999_000, '2026-08-13 00:10:00');

    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']);

    expect(RepurchaseMonthlySnapshot::query()->sole()->balance_paise)->toBe(20_000);
});

it('--force discards the month and freezes it again, with an audit trail', function (): void {
    $dist = Distributor::factory()->create();
    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 20_000, '2026-08-13 00:10:00');

    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']);

    seedRepurchaseEntry($dist->id, 'repurchase_wallet_used', -20_000, '2026-08-25 09:00:00');

    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08', '--force' => true]);

    $row = RepurchaseMonthlySnapshot::query()->sole();

    expect($row->balance_paise)->toBe(0)
        ->and($row->was_zeroed)->toBeTrue();

    $audit = AuditLog::where('action', 'repurchase.snapshot.refrozen')->sole();

    expect($audit->details['reason'])->toBe('forced')
        ->and($audit->details['discarded_snapshots'])->toBe(1)
        ->and($audit->details['discarded_balance_paise'])->toBe(20_000);
});

it('replaces a freeze that was written before the month had closed', function (): void {
    $dist = Distributor::factory()->create();
    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 20_000, '2026-08-13 00:10:00');

    // A mid-month run — the recompute tool catching up the period in flight, or
    // a manual trigger — froze the month on the 20th.
    Carbon::setTestNow('2026-08-20 12:00:00');
    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']);
    Carbon::setTestNow();

    expect(RepurchaseMonthlySnapshot::query()->sole()->balance_paise)->toBe(20_000);

    seedRepurchaseEntry($dist->id, 'repurchase_deduction', 30_000, '2026-08-28 00:10:00');

    Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08']);

    expect(RepurchaseMonthlySnapshot::query()->sole()->balance_paise)->toBe(50_000);
    expect(AuditLog::where('action', 'repurchase.snapshot.refrozen')->sole()->details['reason'])
        ->toBe('premature_freeze');
});

it('rejects a --month that is not YYYY-MM', function (): void {
    expect(Artisan::call('compensation:repurchase-snapshot', ['--month' => '2026-08-31']))->toBe(1);
    expect(RepurchaseMonthlySnapshot::query()->count())->toBe(0);
});

it('never updates a snapshot row in place', function (): void {
    $dist = Distributor::factory()->create();

    $row = RepurchaseMonthlySnapshot::create([
        'distributor_id' => $dist->id,
        'cycle_month' => '2026-08-01',
        'balance_paise' => 20_000,
        'was_zeroed' => false,
        'snapshotted_at' => now(),
    ]);

    expect(fn () => $row->update(['balance_paise' => 0]))->toThrow(LogicException::class);
});
