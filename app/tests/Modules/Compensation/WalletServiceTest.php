<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

it('balance returns 0 for new distributor', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    expect($svc->balancePaise($dist->id))->toBe(0);
});

it('credit adds a positive entry', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 100_000, 'gsb_credit', 1, 'gsb_cutoff_result', 'GSB for 24 Jun');
    expect($svc->balancePaise($dist->id))->toBe(100_000);
});

it('leaves engine_run_id null outside an engine run', function () {
    // Order-time and admin-correction entries have no run to attribute them to;
    // only the Run events "Ledger" column depends on the distinction.
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);

    expect($svc->credit($dist->id, 100_000, 'manual_credit')->engine_run_id)->toBeNull();
    expect($svc->debit($dist->id, 40_000, 'payout_debit')->engine_run_id)->toBeNull();
});

it('debit subtracts from balance', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    $svc->debit($dist->id, 40_000, 'payout_debit');
    expect($svc->balancePaise($dist->id))->toBe(60_000);
});

it('balance is the sum of all signed entries', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 552_900, 'gsb_credit', walletRef(), 'test_reference');    // ₹5,529
    $svc->credit($dist->id, 27_640, 'mb_credit', walletRef(), 'test_reference');      // ₹276.40
    $svc->debit($dist->id, 552_900, 'payout_debit');
    expect($svc->balancePaise($dist->id))->toBe(27_640);
});

it('creditTotalsByMonth buckets positive credits into six zero-filled IST months', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $dist->id, 'type' => 'gsb_credit', 'amount_paise' => 200_000, 'created_at' => now()],
        ['distributor_id' => $dist->id, 'type' => 'mb_credit', 'amount_paise' => 100_000, 'created_at' => now()->subMonthsNoOverflow(2)],
        ['distributor_id' => $dist->id, 'type' => 'gsb_credit', 'amount_paise' => 500_000, 'created_at' => now()->subMonthsNoOverflow(7)], // outside window
        ['distributor_id' => $dist->id, 'type' => 'payout_debit', 'amount_paise' => -50_000, 'created_at' => now()],
    ]);

    $series = $svc->creditTotalsByMonth($dist->id, 6);
    $nowIst = now()->timezone('Asia/Kolkata');

    expect($series)->toHaveCount(6)
        ->and(array_key_last($series))->toBe($nowIst->format('Y-m'))
        ->and($series[$nowIst->format('Y-m')])->toBe(200_000)
        ->and($series[$nowIst->copy()->subMonthsNoOverflow(2)->format('Y-m')])->toBe(100_000)
        ->and(array_sum($series))->toBe(300_000);
});

it('stamps the earned month on all three entries of a bonus credit', function () {
    // The gross credit, the repurchase_transfer debit and the repurchase_deduction
    // credit are one economic event and must agree on which month they belong to.
    $dist = Distributor::factory()->create();

    Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 45));

    app(WalletService::class)->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gbb_credit',
        referenceId: walletRef(),
        referenceType: 'gbb_monthly_result',
        bonusMonth: Carbon::create(2026, 8, 1),
    );

    $months = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->pluck('bonus_month', 'type')
        ->map(fn ($month) => $month?->toDateString());

    expect($months)->toHaveCount(3)
        ->and($months['gbb_credit'])->toBe('2026-08-01')
        ->and($months['repurchase_transfer'])->toBe('2026-08-01')
        ->and($months['repurchase_deduction'])->toBe('2026-08-01');
});

it('shares one ceiling across bonuses for the same earned month written in different calendar months', function () {
    // August's engines run on 1 September; a release listener can pay another
    // August row weeks later. Both are August income and both draw on August's
    // ₹10,000 ceiling — the second one gets only what the first left.
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);

    Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 30));
    $first = $svc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 8_000_000,   // ₹80,000 → 10% = ₹8,000
        bonusType: 'rank_credit',
        referenceId: walletRef(),
        referenceType: 'rank_bonus_result',
        bonusMonth: $august,
    );

    Carbon::setTestNow(Carbon::create(2026, 10, 3, 11));
    $second = $svc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 8_000_000,   // ₹80,000 → 10% = ₹8,000, but only ₹2,000 of August is left
        bonusType: 'gbb_credit',
        referenceId: walletRef(),
        referenceType: 'gbb_monthly_result',
        bonusMonth: $august,
    );

    expect($first->repurchaseDeductionPaise)->toBe(800_000)
        ->and($second->repurchaseDeductionPaise)->toBe(200_000)
        ->and($svc->repurchaseDeductionForMonthPaise($dist->id, $august))->toBe(1_000_000);
});

it('does not let August income written on 1 September consume September\'s ceiling', function () {
    // The defect this column exists to fix: the monthly engines fire in the small
    // hours of the 1st for the month that just closed.
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);

    Carbon::setTestNow(Carbon::create(2026, 9, 1, 1, 0));
    $august = $svc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 20_000_000,  // ₹2,00,000 → 10% = ₹20,000, clipped to the ₹10,000 ceiling
        bonusType: 'fortune_credit',
        referenceId: walletRef(),
        referenceType: 'fortune_bonus_result',
        bonusMonth: Carbon::create(2026, 8, 1),
    );

    Carbon::setTestNow(Carbon::create(2026, 9, 15, 9));
    $september = $svc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 8_000_000,   // ₹80,000 → 10% = ₹8,000, September's ceiling is untouched
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: Carbon::create(2026, 9, 1),
    );

    expect($august->repurchaseDeductionPaise)->toBe(1_000_000)
        ->and($september->repurchaseDeductionPaise)->toBe(800_000)
        ->and($svc->repurchaseDeductionForMonthPaise($dist->id, Carbon::create(2026, 9, 1)))->toBe(800_000);
});

it('counts a null bonus_month row against the month it was created in', function () {
    // Rows written before the column existed have no earned month to window on,
    // so they keep answering under the created_at fallback and still consume the
    // ceiling of the month they landed in.
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);

    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $dist->id,
        'type' => 'repurchase_deduction',
        'amount_paise' => 900_000,          // ₹9,000 of August's ₹10,000 already gone
        'reference_id' => walletRef(),
        'reference_type' => 'gsb_cutoff_result',
        'bonus_month' => null,
        'created_at' => '2026-08-15 12:00:00',
    ]);

    expect($svc->repurchaseDeductionForMonthPaise($dist->id, Carbon::create(2026, 8, 1)))->toBe(900_000)
        ->and($svc->repurchaseDeductionForMonthPaise($dist->id, Carbon::create(2026, 9, 1)))->toBe(0);

    Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 30));
    $outcome = $svc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 8_000_000,   // ₹80,000 → 10% = ₹8,000, but only ₹1,000 of August is left
        bonusType: 'rank_credit',
        referenceId: walletRef(),
        referenceType: 'rank_bonus_result',
        bonusMonth: Carbon::create(2026, 8, 1),
    );

    expect($outcome->repurchaseDeductionPaise)->toBe(100_000);
});

/**
 * Credit ₹50,000 of August GSB (10% → ₹5,000 held back) and hand the test the
 * reference the reversal has to quote.
 *
 * @return array{0: Distributor, 1: WalletService, 2: int}
 */
function creditedAugustBonus(): array
{
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $ref = walletRef();

    Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 30));
    $svc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 5_000_000,
        bonusType: 'gsb_credit',
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: Carbon::create(2026, 8, 1),
    );

    return [$dist, $svc, $ref];
}

it('a reversal puts the repurchase wallet back and frees the earned month\'s ceiling', function () {
    // The defect: the net came out of the main wallet but the repurchase wallet
    // kept its ₹5,000 share of a bonus that no longer exists, and that phantom
    // balance then failed the wallet-zero gates on Fortune, GBB and Rank.
    [$dist, $svc, $ref] = creditedAugustBonus();
    $august = Carbon::create(2026, 8, 1);

    expect($svc->balancePaise($dist->id))->toBe(4_500_000)
        ->and($svc->repurchaseWalletBalancePaise($dist->id))->toBe(500_000)
        ->and($svc->repurchaseDeductionForMonthPaise($dist->id, $august))->toBe(500_000);

    Carbon::setTestNow(Carbon::create(2026, 9, 10, 15));
    $outcome = $svc->reverseBonusCredit(
        distributorId: $dist->id,
        netPaise: 4_500_000,
        repurchaseDeductionPaise: 500_000,
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
        memo: 'Admin reversal — wrong slab',
    );

    expect($outcome)->not->toBeNull()
        ->and($outcome->repurchaseReversedPaise)->toBe(500_000)
        ->and($outcome->repurchaseShortfallPaise)->toBe(0)
        ->and($svc->balancePaise($dist->id))->toBe(0)
        ->and($svc->repurchaseWalletBalancePaise($dist->id))->toBe(0)
        // August's ceiling gets the room back, not September's — the reversal
        // was keyed in on 10 September but the income was August's.
        ->and($svc->repurchaseDeductionForMonthPaise($dist->id, $august))->toBe(0);
});

it('writes the unwind as a negative repurchase_deduction carrying the original earned month', function () {
    // A generic `reversal` row would be invisible to both the balance and the
    // monthly ceiling, which are sums over the `repurchase_deduction` type.
    [$dist, $svc, $ref] = creditedAugustBonus();

    $svc->reverseBonusCredit(
        distributorId: $dist->id,
        netPaise: 4_500_000,
        repurchaseDeductionPaise: 500_000,
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: Carbon::create(2026, 8, 1),
    );

    $unwind = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'repurchase_deduction')
        ->where('amount_paise', '<', 0)
        ->sole();

    expect($unwind->amount_paise)->toBe(-500_000)
        ->and($unwind->bonus_month->toDateString())->toBe('2026-08-01')
        // Distinct reference_type: uniq_wallet_ledger_source covers
        // (type, reference_type, reference_id) and the credit already holds
        // ('repurchase_deduction', 'gsb_cutoff_result', $ref).
        ->and($unwind->reference_type)->toBe('gsb_cutoff_result_reversal')
        ->and($unwind->reference_id)->toBe($ref);
});

it('clamps the unwind to what is left in the repurchase wallet and reports the shortfall', function () {
    // The credit was spent at checkout before the admin reversed the bonus. The
    // goods have shipped, so only what is still there comes back and the rest is
    // recorded as the company's loss — the balance never goes negative.
    [$dist, $svc, $ref] = creditedAugustBonus();
    $august = Carbon::create(2026, 8, 1);

    $svc->debit($dist->id, 300_000, 'repurchase_wallet_used', walletRef(), 'order', 'Applied at checkout');
    expect($svc->repurchaseWalletBalancePaise($dist->id))->toBe(200_000);

    $outcome = $svc->reverseBonusCredit(
        distributorId: $dist->id,
        netPaise: 4_500_000,
        repurchaseDeductionPaise: 500_000,
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
    );

    expect($outcome->repurchaseReversedPaise)->toBe(200_000)
        ->and($outcome->repurchaseShortfallPaise)->toBe(300_000)
        ->and($svc->repurchaseWalletBalancePaise($dist->id))->toBe(0)
        // Only the part that came back frees ceiling room; the ₹3,000 that was
        // genuinely spent stays consumed for August.
        ->and($svc->repurchaseDeductionForMonthPaise($dist->id, $august))->toBe(300_000);

    $signedSum = (int) WalletLedgerEntry::where('distributor_id', $dist->id)
        ->whereIn('type', WalletService::REPURCHASE_TYPES)
        ->sum('amount_paise');

    expect($signedSum)->toBe(0);
});

it('writes nothing at all when the repurchase wallet is already empty', function () {
    [$dist, $svc, $ref] = creditedAugustBonus();

    $svc->debit($dist->id, 500_000, 'repurchase_wallet_used', walletRef(), 'order', 'Applied at checkout');

    $outcome = $svc->reverseBonusCredit(
        distributorId: $dist->id,
        netPaise: 4_500_000,
        repurchaseDeductionPaise: 500_000,
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: Carbon::create(2026, 8, 1),
    );

    expect($outcome->repurchaseReversedPaise)->toBe(0)
        ->and($outcome->repurchaseShortfallPaise)->toBe(500_000)
        ->and($svc->repurchaseWalletBalancePaise($dist->id))->toBe(0)
        ->and(WalletLedgerEntry::where('distributor_id', $dist->id)
            ->where('type', 'repurchase_deduction')
            ->where('amount_paise', '<', 0)
            ->exists())->toBeFalse();
});

it('refuses to reverse the same bonus row twice', function () {
    // A double-submitted admin form must not debit the wallet twice.
    [$dist, $svc, $ref] = creditedAugustBonus();

    $args = [
        'distributorId' => $dist->id,
        'netPaise' => 4_500_000,
        'repurchaseDeductionPaise' => 500_000,
        'referenceId' => $ref,
        'referenceType' => 'gsb_cutoff_result',
        'bonusMonth' => Carbon::create(2026, 8, 1),
    ];

    expect($svc->reverseBonusCredit(...$args))->not->toBeNull()
        ->and($svc->reverseBonusCredit(...$args))->toBeNull()
        ->and($svc->balancePaise($dist->id))->toBe(0)
        ->and($svc->repurchaseWalletBalancePaise($dist->id))->toBe(0)
        ->and(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'reversal')->count())->toBe(1);
});
