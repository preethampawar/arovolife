<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Enums\PurchaseOfferType;
use App\Modules\Commerce\Models\MonthlyOfferProduct;
use App\Modules\Commerce\Models\PurchaseOfferGrant;
use App\Modules\Commerce\Services\PurchaseOfferSettings;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * Announcing the month's half-price product, and reading what the offers
 * granted.
 *
 * The announcement is the gate on the whole half-price offer: with no product
 * named for a month, the engine grants nothing that month. That is deliberate
 * — an entitlement to an unnamed product is not an entitlement, and the
 * published text promises "the company's monthly-announced product".
 */
final class AdminOfferController extends Controller
{
    public function __construct(private readonly PurchaseOfferSettings $settings) {}

    public function index(Request $request): View
    {
        $this->guardFeature();

        $month = $this->resolveMonth($request);

        $grants = PurchaseOfferGrant::with(['distributor.user', 'variant.product'])
            ->whereDate('month_start', $month->toDateString())
            ->orderBy('offer_type')
            ->paginate(25)
            ->withQueryString();

        return view('admin.commerce.offers.index', [
            'month' => $month,
            'announced' => MonthlyOfferProduct::with('variant.product')
                ->whereDate('month_start', $month->toDateString())
                ->first(),
            'grants' => $grants,
            'variants' => ProductVariant::with('product')
                ->whereHas('product', fn ($q) => $q->where('status', 'active'))
                ->orderBy('variant_sku')
                ->get(),
            'settings' => $this->settings,
            'totals' => [
                'half_price' => PurchaseOfferGrant::whereDate('month_start', $month->toDateString())
                    ->where('offer_type', PurchaseOfferType::HalfPriceProduct->value)->count(),
                'points_grants' => PurchaseOfferGrant::whereDate('month_start', $month->toDateString())
                    ->where('offer_type', PurchaseOfferType::RedeemPoints->value)->count(),
                'points_awarded' => (int) PurchaseOfferGrant::whereDate('month_start', $month->toDateString())
                    ->sum('points_awarded'),
            ],
        ]);
    }

    /**
     * Name (or rename) the month's half-price product.
     *
     * Renaming is allowed while the month has no grants. Once the engine has
     * granted against a product, changing it would leave distributors holding
     * an entitlement to something the register says was never offered — so the
     * change is refused rather than quietly rewriting history.
     */
    public function announce(Request $request): RedirectResponse
    {
        $this->guardFeature();

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $monthStart = Carbon::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfMonth();

        $existingGrants = PurchaseOfferGrant::whereDate('month_start', $monthStart->toDateString())
            ->where('offer_type', PurchaseOfferType::HalfPriceProduct->value)
            ->exists();

        if ($existingGrants) {
            return back()->withErrors([
                'product_variant_id' => 'The offer has already been granted for this month. Changing the product now would leave distributors holding an entitlement the register says was never offered.',
            ]);
        }

        $announcement = MonthlyOfferProduct::updateOrCreate(
            ['month_start' => $monthStart->toDateString()],
            [
                'product_variant_id' => (int) $validated['product_variant_id'],
                'created_by_user_id' => Auth::id(),
                'notes' => $validated['notes'] ?? null,
            ],
        );

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'offers.product_announced',
            'subject_type' => 'monthly_offer_product',
            'subject_id' => $announcement->id,
            'details' => [
                'month' => $monthStart->format('Y-m'),
                'product_variant_id' => $announcement->product_variant_id,
            ],
        ]);

        return redirect()
            ->route('admin.commerce.offers.index', ['month' => $monthStart->format('Y-m')])
            ->with('status', 'Half-price product announced for '.$monthStart->format('F Y').'.');
    }

    /** Zero-trace gating — an unlaunched offer leaves no evidence it exists. */
    private function guardFeature(): void
    {
        abort_unless(Feature::for(null)->active(PurchaseOffersFeature::class), 404);
    }

    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            $parsed = Carbon::createFromFormat('Y-m-d', $raw.'-01');

            if ($parsed !== null) {
                return $parsed->startOfMonth();
            }
        }

        return Carbon::now('Asia/Kolkata')->startOfMonth();
    }
}
