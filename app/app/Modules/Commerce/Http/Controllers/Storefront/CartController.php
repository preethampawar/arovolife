<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Services\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService) {}

    public function show(Request $request): View
    {
        $cart = $this->cartService->currentCart($request);
        $cart->load('items.variant.product');

        return view('shop.cart', ['cart' => $cart]);
    }

    public function add(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $cart = $this->cartService->currentCart($request);
        $this->cartService->addItem($cart, (int) $validated['product_variant_id'], (int) ($validated['qty'] ?? 1));

        return redirect()->route('shop.cart')->with('status', 'Added to cart.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $this->cartService->updateQty($item, (int) $validated['qty']);

        return redirect()->route('shop.cart');
    }

    public function remove(CartItem $item): RedirectResponse
    {
        $this->cartService->remove($item);

        return redirect()->route('shop.cart');
    }
}
