<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Commerce\Http\Requests\CouponRequest;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class AdminCouponController extends Controller
{
    public function index(): View
    {
        $coupons = Coupon::query()->orderByDesc('id')->paginate(25);

        return view('admin.commerce.coupons.index', ['coupons' => $coupons]);
    }

    public function create(): View
    {
        return view('admin.commerce.coupons.form', [
            'coupon' => new Coupon(['type' => Coupon::TYPE_PERCENT, 'scope' => Coupon::SCOPE_ALL, 'status' => Coupon::STATUS_ACTIVE]),
            'categories' => $this->categoryOptions(),
            'products' => $this->productOptions(),
        ]);
    }

    public function store(CouponRequest $request): RedirectResponse
    {
        $coupon = Coupon::create($this->fromRequest($request) + ['created_by_user_id' => Auth::id()]);
        $this->audit('commerce.coupon.created', $coupon);

        return redirect()->route('admin.commerce.coupons.index')->with('status', "Coupon \"{$coupon->code}\" created.");
    }

    public function edit(Coupon $coupon): View
    {
        return view('admin.commerce.coupons.form', [
            'coupon' => $coupon,
            'categories' => $this->categoryOptions(),
            'products' => $this->productOptions(),
        ]);
    }

    public function update(CouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $coupon->update($this->fromRequest($request));
        $this->audit('commerce.coupon.updated', $coupon);

        return redirect()->route('admin.commerce.coupons.edit', $coupon)->with('status', 'Coupon saved.');
    }

    public function archive(Coupon $coupon): RedirectResponse
    {
        $coupon->update(['status' => Coupon::STATUS_ARCHIVED]);
        $this->audit('commerce.coupon.archived', $coupon);

        return redirect()->route('admin.commerce.coupons.index')->with('status', "Coupon \"{$coupon->code}\" archived.");
    }

    /**
     * Map the human-unit form inputs to stored values. `value`/`max_discount`/
     * `min_purchase` arrive in percent or rupees; fixed amounts persist as paise.
     *
     * @return array<string, mixed>
     */
    private function fromRequest(CouponRequest $request): array
    {
        $data = $request->validated();
        $isPercent = $data['type'] === Coupon::TYPE_PERCENT;

        return [
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'value' => $isPercent ? (int) $data['value'] : $this->toPaise($data['value']),
            'max_discount_paise' => ($isPercent && isset($data['max_discount']) && $data['max_discount'] !== null)
                ? $this->toPaise($data['max_discount']) : null,
            'min_purchase_paise' => isset($data['min_purchase']) ? $this->toPaise($data['min_purchase']) : 0,
            'scope' => $data['scope'],
            'scope_id' => $data['scope'] === Coupon::SCOPE_ALL ? null : ($data['scope_id'] ?? null),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'status' => $data['status'],
        ];
    }

    private function toPaise(mixed $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }

    private function categoryOptions()
    {
        return ProductCategory::query()->where('status', 'active')->orderBy('sort')->get(['id', 'name']);
    }

    private function productOptions()
    {
        return Product::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
    }

    private function audit(string $action, Coupon $coupon): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'coupon',
            'subject_id' => $coupon->id,
            'details' => [
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'max_discount_paise' => $coupon->max_discount_paise,
                'min_purchase_paise' => $coupon->min_purchase_paise,
                'scope' => $coupon->scope,
                'scope_id' => $coupon->scope_id,
                'usage_limit' => $coupon->usage_limit,
                'per_customer_limit' => $coupon->per_customer_limit,
                'status' => $coupon->status,
            ],
        ]);
    }
}
