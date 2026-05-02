<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Ledger\Exceptions\UnbalancedLedgerException;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Ledger\Services\LedgerPoster;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DoubleEntryBalancesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    public function test_balanced_post_succeeds(): void
    {
        $poster = app(LedgerPoster::class);

        $tx = $poster->transfer(
            sourceModule: 'Test',
            sourceType: 'test.balanced',
            sourceId: null,
            idempotencyKey: 'test-balanced-1',
            debitAccount: 'asset.cash.gateway.razorpay',
            creditAccount: 'liability.customer_prepayment',
            amountPaise: 29500,
        );

        $this->assertNotNull($tx->id);
        $this->assertSame(2, $tx->entries()->count());
    }

    public function test_unbalanced_post_throws(): void
    {
        $poster = app(LedgerPoster::class);
        $this->expectException(UnbalancedLedgerException::class);

        $poster->post(
            sourceModule: 'Test',
            sourceType: 'test.unbalanced',
            sourceId: null,
            idempotencyKey: 'test-unbal-1',
            lines: [
                ['account' => 'asset.cash.gateway.razorpay',   'side' => 'debit',  'amount_paise' => 100],
                ['account' => 'liability.customer_prepayment', 'side' => 'credit', 'amount_paise' => 99],
            ],
        );
    }

    public function test_idempotency_returns_existing_tx(): void
    {
        $poster = app(LedgerPoster::class);

        $first = $poster->transfer('Test', 't', null, 'idem-1',
            'asset.cash.gateway.razorpay', 'liability.customer_prepayment', 100);
        $second = $poster->transfer('Test', 't', null, 'idem-1',
            'asset.cash.gateway.razorpay', 'liability.customer_prepayment', 100);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerTx::count());
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_every_tx_balances_across_many_random_transfers(): void
    {
        $poster = app(LedgerPoster::class);

        for ($i = 0; $i < 200; $i++) {
            $amount = random_int(100, 1_000_000);
            $poster->transfer('Test', "rand.{$i}", null, "rand-{$i}",
                'asset.cash.gateway.razorpay', 'liability.customer_prepayment', $amount);
        }

        // Global sum: debits == credits
        $debitSum = LedgerEntry::where('side', 'debit')->sum('amount_paise');
        $creditSum = LedgerEntry::where('side', 'credit')->sum('amount_paise');
        $this->assertSame((int) $debitSum, (int) $creditSum, 'Ledger debit/credit totals must match.');

        // Per-tx balance via raw SQL
        $row = DB::selectOne('
            SELECT COUNT(*) AS c FROM (
                SELECT ledger_tx_id,
                       SUM(CASE WHEN side = "debit"  THEN amount_paise ELSE 0 END)
                     - SUM(CASE WHEN side = "credit" THEN amount_paise ELSE 0 END) AS diff
                FROM ledger_entries
                GROUP BY ledger_tx_id
                HAVING diff <> 0
            ) t
        ');
        $this->assertSame(0, (int) $row->c, 'Every ledger_tx must balance internally.');
    }
}
