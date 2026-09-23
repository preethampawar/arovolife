@extends('admin.layouts.admin')
@section('title', 'New offline order')
@section('heading', 'New offline order')

@section('content')
@php
    use App\Modules\Shared\Support\IndianNumber;
    use App\Modules\Shared\Support\IndianStates;

    // One lookup for every field: a failed POST's old input first, then the
    // buyer's saved address.
    $val = fn (string $key, $default = null) => old($key, $prefill[$key] ?? $default);
    $qty = fn (int $id): int => (int) old('qty.'.$id, 0);
    $isCollect = $val('delivery_type', 'ship') === 'collect';
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500';
    $label = 'block text-xs font-medium text-gray-600 mb-1';
@endphp

<div class="max-w-5xl space-y-6">
    <p class="text-sm text-gray-600">
        Use this when a distributor has paid outside the website — cash at reception, a bank deposit, UPI, NEFT or a cheque.
        The order is created as <strong>Placed</strong> and waits for finance to confirm the money. BV is counted only after that confirmation.
        A purchase is never required to join or to stay a distributor.
    </p>


    {{-- Step 1: the buyer --}}
    <x-ui.card padding="p-6">
        <h3 class="font-semibold text-gray-900 mb-3">1. Distributor</h3>
        <form method="GET" action="{{ route('admin.commerce.offline-orders.create') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="adn-lookup" class="{{ $label }}">ADN <x-help-tip text="The 9-digit Arovolife Distributor Number of the person who paid. The order is theirs and their BV is counted on it." /></label>
                <input id="adn-lookup" name="adn" value="{{ $adn }}" inputmode="numeric" maxlength="9" placeholder="9-digit ADN" class="{{ $input }} w-48 font-mono">
            </div>
            <x-ui.button variant="secondary" icon="search">Find</x-ui.button>
        </form>

        @if($adn !== '' && $distributor === null)
        <p class="mt-3 text-sm text-red-700">No active distributor with that ADN. Blocked, terminated and rejected accounts cannot be ordered for.</p>
        @elseif($distributor)
        <p class="mt-3 text-sm text-gray-700">
            <span class="font-medium text-gray-900">{{ $distributor->user->full_name }}</span>
            · <span class="font-mono">{{ $distributor->adn }}</span>
            · {{ $distributor->user->statusLabel() }}
        </p>
        @endif
    </x-ui.card>

    @if($distributor)
    <form id="offline-order-form" method="POST" action="{{ route('admin.commerce.offline-orders.store') }}" enctype="multipart/form-data" class="space-y-6"
          data-confirm="Create this offline order?"
          data-confirm-title="Create offline order"
          data-confirm-impact="Impact: creates the order in PLACED and reserves the stock. Nothing is counted — no BV, no invoice, nothing in the books — until finance confirms the payment.">
        @csrf
        <input type="hidden" name="adn" value="{{ $distributor->adn }}">
        <input type="hidden" name="form_token" value="{{ $formToken }}">

        {{-- Step 2: products --}}
        <x-ui.card padding="p-6">
            <h3 class="font-semibold text-gray-900 mb-1">2. Products</h3>
            <p class="text-xs text-gray-600 mb-4">Prices are the ones this distributor pays in the shop. Enter a quantity for each product they bought.</p>
            <div class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50">
                        <tr class="border-b border-gray-200">
                            <th class="text-left px-3 py-2 text-xs font-medium text-gray-600 uppercase">SKU</th>
                            <th class="text-left px-3 py-2 text-xs font-medium text-gray-600 uppercase">Product</th>
                            <th class="text-right px-3 py-2 text-xs font-medium text-gray-600 uppercase">Price</th>
                            <th class="text-right px-3 py-2 text-xs font-medium text-gray-600 uppercase">BV</th>
                            <th class="text-right px-3 py-2 text-xs font-medium text-gray-600 uppercase w-28">Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($catalogue as $item)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs text-gray-700">{{ $item['sku'] }}</td>
                            <td class="px-3 py-2 text-gray-900"><label for="qty-{{ $item['id'] }}">{{ $item['name'] }}</label></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ IndianNumber::rupees($item['unit_price_paise']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-brand-700">@bv($item['bv_paise'])</td>
                            <td class="px-3 py-2 text-right">
                                <input id="qty-{{ $item['id'] }}" type="number" min="0" max="999" name="qty[{{ $item['id'] }}]" value="{{ $qty($item['id']) ?: '' }}" placeholder="0"
                                       class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm text-right bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                            </td>
                        </tr>
                        @empty
                        <x-ui.empty-state colspan="5" title="No products are available to order." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Step 3: delivery --}}
        <x-ui.card padding="p-6">
            <h3 class="font-semibold text-gray-900 mb-3">3. Delivery</h3>
            <fieldset class="flex flex-wrap gap-6 mb-4">
                <legend class="sr-only">Delivery type</legend>
                <label class="inline-flex items-center gap-2 text-sm text-gray-800">
                    <input type="radio" name="delivery_type" value="ship" @checked(! $isCollect) class="text-brand-700 focus:ring-brand-500"> Deliver to an address
                </label>
                @if($centres->isNotEmpty())
                <label class="inline-flex items-center gap-2 text-sm text-gray-800">
                    <input type="radio" name="delivery_type" value="collect" @checked($isCollect) class="text-brand-700 focus:ring-brand-500"> Collect from an Arete centre
                </label>
                @endif
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="buyer_name" class="{{ $label }}">Receiver's name</label>
                    <input id="buyer_name" name="buyer_name" value="{{ $val('buyer_name') }}" maxlength="150" class="{{ $input }}">
                </div>
                <div>
                    <label for="buyer_phone" class="{{ $label }}">Receiver's mobile</label>
                    <input id="buyer_phone" name="buyer_phone" value="{{ $val('buyer_phone') }}" inputmode="numeric" maxlength="10" placeholder="10-digit mobile" class="{{ $input }}">
                </div>

                @if($centres->isNotEmpty())
                <div class="sm:col-span-2">
                    <label for="arete_center_id" class="{{ $label }}">Collection centre <x-help-tip text="Only used when the buyer collects. The parcel is consigned to this centre." /></label>
                    <select id="arete_center_id" name="arete_center_id" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach($centres as $centre)
                        <option value="{{ $centre->id }}" @selected((string) $val('arete_center_id') === (string) $centre->id)>{{ $centre->name }} — {{ $centre->displayLocation() }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div class="sm:col-span-2">
                    <label for="ship_line1" class="{{ $label }}">Address line 1 <span class="text-gray-500">(delivery only)</span></label>
                    <input id="ship_line1" name="ship_line1" value="{{ $val('ship_line1') }}" maxlength="255" class="{{ $input }}">
                </div>
                <div class="sm:col-span-2">
                    <label for="ship_line2" class="{{ $label }}">Address line 2</label>
                    <input id="ship_line2" name="ship_line2" value="{{ $val('ship_line2') }}" maxlength="255" class="{{ $input }}">
                </div>
                <div>
                    <label for="ship_city" class="{{ $label }}">City</label>
                    <input id="ship_city" name="ship_city" value="{{ $val('ship_city') }}" maxlength="100" class="{{ $input }}">
                </div>
                <div>
                    <label for="ship_state" class="{{ $label }}">State</label>
                    <select id="ship_state" name="ship_state" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(IndianStates::all() as $state)
                        <option value="{{ $state }}" @selected($val('ship_state') === $state)>{{ $state }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="ship_pincode" class="{{ $label }}">Pincode</label>
                    <input id="ship_pincode" name="ship_pincode" value="{{ $val('ship_pincode') }}" inputmode="numeric" maxlength="6" class="{{ $input }}">
                </div>
            </div>
        </x-ui.card>

        {{-- Quote: fetched in the background (quote route) so the page never
             reloads and no buyer detail ever goes into a URL. --}}
        <x-ui.card padding="p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold text-gray-900">Order total</h3>
                <x-ui.button type="button" variant="secondary" icon="calculator" id="calculate-total">Calculate total</x-ui.button>
            </div>
            <p id="quote-hint" class="mt-3 text-sm text-gray-600">Enter the quantities and delivery, then press <strong>Calculate total</strong> to see the exact amount to collect.</p>
            <p id="quote-error" class="mt-3 text-sm text-red-700 hidden" role="alert"></p>
            <dl id="quote-box" class="mt-4 grid grid-cols-2 sm:grid-cols-5 gap-4 text-sm hidden">
                <div><dt class="text-xs text-gray-600">Products</dt><dd class="font-medium tabular-nums" data-quote="subtotal"></dd></div>
                <div><dt class="text-xs text-gray-600">GST (included)</dt><dd class="tabular-nums" data-quote="gst"></dd></div>
                <div><dt class="text-xs text-gray-600">Shipping / collection</dt><dd class="tabular-nums" data-quote="fulfilment"></dd></div>
                <div><dt class="text-xs text-gray-600">BV</dt><dd class="tabular-nums text-brand-700" data-quote="bv"></dd></div>
                <div><dt class="text-xs text-gray-600">Amount to collect</dt><dd class="text-lg font-bold tabular-nums text-gray-900" data-quote="total" data-testid="quote-total"></dd></div>
            </dl>
        </x-ui.card>

        {{-- Step 4: payment --}}
        <x-ui.card padding="p-6">
            <h3 class="font-semibold text-gray-900 mb-1">4. Payment received</h3>
            <p class="text-xs text-gray-600 mb-4">The amount must match the order total exactly. Cash from one person must stay under ₹2 lakh in a day (Income Tax Act s.269ST).</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="channel" class="{{ $label }}">Paid by</label>
                    <select id="channel" name="channel" class="{{ $input }}">
                        @foreach($channels as $value => $text)
                        <option value="{{ $value }}" @selected($val('channel', 'bank_deposit') === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="channel_other" class="{{ $label }}">If "Other", which channel</label>
                    <input id="channel_other" name="channel_other" value="{{ $val('channel_other') }}" maxlength="60" class="{{ $input }}">
                </div>
                <div>
                    <label for="amount" class="{{ $label }}">Amount received (₹)</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="1" value="{{ $val('amount') }}" class="{{ $input }} tabular-nums">
                </div>
                <div>
                    <label for="received_on" class="{{ $label }}">Date received</label>
                    <input id="received_on" name="received_on" type="date" max="{{ now()->toDateString() }}" value="{{ $val('received_on', now()->toDateString()) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="reference_no" class="{{ $label }}">Reference no. <x-help-tip text="UTR for a bank transfer, the UPI transaction ID, the cheque number, or the receipt number. Needed for every channel except cash. One reference can back only one order." /></label>
                    <input id="reference_no" name="reference_no" value="{{ $val('reference_no') }}" maxlength="64" class="{{ $input }} font-mono">
                </div>
                <div>
                    <label for="payer_name" class="{{ $label }}">Paid by (name) <span class="text-gray-500">(optional)</span></label>
                    <input id="payer_name" name="payer_name" value="{{ $val('payer_name') }}" maxlength="150" class="{{ $input }}">
                </div>
                <div class="sm:col-span-2">
                    <label for="notes" class="{{ $label }}">Notes <span class="text-gray-500">(optional)</span></label>
                    <textarea id="notes" name="notes" rows="2" maxlength="1000" class="{{ $input }}">{{ $val('notes') }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <label for="proof" class="{{ $label }}">Proof of payment <span class="text-gray-500">(optional — JPG, PNG or PDF, up to 5 MB)</span> <x-help-tip text="A deposit slip, UPI screenshot or cash receipt. Stored encrypted; only finance and operations can open it, and every view is logged." /></label>
                    <input id="proof" name="proof" type="file" accept="image/jpeg,image/png,application/pdf" class="block text-sm text-gray-700">
                </div>
                <div class="sm:col-span-2">
                    <label class="inline-flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" name="terms_acknowledged" value="1" @checked($val('terms_acknowledged')) class="mt-0.5 rounded border-gray-300 text-brand-700 focus:ring-brand-500">
                        <span>The buyer has agreed to the terms of sale.</span>
                    </label>
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-3">
            <x-ui.button variant="secondary" :href="route('admin.commerce.orders.index')">Cancel</x-ui.button>
            <x-ui.button icon="receipt">Create offline order</x-ui.button>
        </div>
    </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('offline-order-form');
    var button = document.getElementById('calculate-total');
    if (!form || !button) { return; }

    var box = document.getElementById('quote-box');
    var hint = document.getElementById('quote-hint');
    var error = document.getElementById('quote-error');

    button.addEventListener('click', function () {
        // Only what the quote needs — never the address, payer or reference.
        var params = new URLSearchParams();
        params.set('adn', form.querySelector('input[name="adn"]').value);
        var delivery = form.querySelector('input[name="delivery_type"]:checked');
        params.set('delivery_type', delivery ? delivery.value : 'ship');
        form.querySelectorAll('input[name^="qty["]').forEach(function (input) {
            if (parseInt(input.value, 10) > 0) { params.append(input.name, input.value); }
        });

        fetch(@json(route('admin.commerce.offline-orders.quote')) + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json().then(function (body) { return { ok: response.ok, body: body }; });
        }).then(function (result) {
            hint.classList.add('hidden');
            if (!result.ok) {
                box.classList.add('hidden');
                error.textContent = result.body.error || 'The total could not be calculated.';
                error.classList.remove('hidden');
                return;
            }
            error.classList.add('hidden');
            ['subtotal', 'gst', 'fulfilment', 'bv', 'total'].forEach(function (key) {
                box.querySelector('[data-quote="' + key + '"]').textContent = result.body[key];
            });
            box.classList.remove('hidden');
            form.querySelector('input[name="amount"]').value = result.body.amount;
        }).catch(function () {
            error.textContent = 'The total could not be calculated. Check your connection and try again.';
            error.classList.remove('hidden');
        });
    });
})();
</script>
@endpush
