<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Enums\PurchaseOfferType;
use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Commerce\Models\RedeemPointEntry;
use App\Modules\Commerce\Services\PurchaseOfferService;
use App\Modules\Commerce\Services\PurchaseOfferSettings;
use App\Modules\Commerce\Services\RedeemPointsService;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "My offers" — a distributor's own point balance, streak and entitlements.
 *
 * Everything on this page is a historical fact about the distributor's own
 * purchases: what they have bought, what that has already earned, and how many
 * qualifying months they have accumulated. Nothing projects forward — no "you
 * could earn", no illustrative figure for a streak not yet completed (hard
 * rule 3, DSR Rule 5(1)(d)). Saying how many months remain in a cycle is a
 * statement about a published rule, not a prediction of income.
 */
final class MyOffersController extends Controller
{
    public function __construct(
        private readonly PurchaseOfferService $offers,
        private readonly RedeemPointsService $points,
        private readonly PurchaseOfferSettings $settings,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(PurchaseOffersFeature::class), 404);

        $distributor = $request->user()?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        $thisMonth = Carbon::now('Asia/Kolkata')->startOfMonth();

        return view('shop.offers.index', [
            'balance' => $this->points->balance($distributor->id),
            'ledger' => RedeemPointEntry::where('distributor_id', $distributor->id)
                ->orderByDesc('created_at')
                ->limit(25)
                ->get(),
            'grants' => PurchaseOfferGrant::with('variant.product')
                ->where('distributor_id', $distributor->id)
                ->orderByDesc('month_start')
                ->limit(12)
                ->get(),
            'activeHalfPrice' => PurchaseOfferGrant::with('variant.product')
                ->where('distributor_id', $distributor->id)
                ->where('offer_type', PurchaseOfferType::HalfPriceProduct->value)
                ->usable()
                ->orderByDesc('month_start')
                ->first(),
            'holdsARank' => $this->offers->holdsARank($distributor->id),
            'streakMonths' => $this->offers->streakLength($distributor->id, $thisMonth->copy()->subMonth()),
            'thisMonthBvPaise' => $this->offers->monthlyBvPaise($distributor->id, $thisMonth),
            'lifetimeBvPaise' => $this->offers->lifetimeBvPaise($distributor->id),
            'settings' => $this->settings,
        ]);
    }
}
