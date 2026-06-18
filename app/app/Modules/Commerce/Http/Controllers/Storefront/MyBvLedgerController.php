<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Services\BvLedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Distributor-facing BV ledger: a read-only chronological list of every accrual
 * and reversal on the authenticated distributor's account, with a running balance
 * and a customer-sale badge for BV sourced from attributed orders (hard rule #3:
 * no income projection — only factual historical BV amounts are shown).
 */
final class MyBvLedgerController extends Controller
{
    private const ENTRIES_PER_PAGE = 25;

    public function __construct(private readonly BvLedgerService $bvLedger) {}

    public function index(Request $request): View
    {
        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        $breakdown = $this->bvLedger->breakdownForDistributor($distributor->id);

        $ordered = BvLedgerEntry::query()
            ->forDistributor($distributor->id)
            ->with('order')
            ->orderBy('effective_at')
            ->orderBy('id');

        $page = max(1, (int) $request->query('page', '1'));
        $offset = ($page - 1) * self::ENTRIES_PER_PAGE;

        // Opening balance for this page = sum of every entry that sorts before
        // the first row shown, so the running balance column is correct across
        // pagination even when the ledger spans many pages.
        $openingBalance = (int) DB::query()
            ->fromSub((clone $ordered)->select('bv_paise')->limit($offset), 't')
            ->sum('bv_paise');

        return view('shop.bv-ledger.index', [
            'breakdown' => $breakdown,
            'entries' => (clone $ordered)->paginate(self::ENTRIES_PER_PAGE)->withQueryString(),
            'openingBalance' => $openingBalance,
        ]);
    }
}
