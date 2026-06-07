<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Storefront;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\CustomerAddress;
use App\Modules\Commerce\Services\CustomerAddressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "My Addresses" — the distributor's saved shipping-address book. CRUD over
 * {@see CustomerAddress} (shipping only) via {@see CustomerAddressService}.
 * Every write is scoped to the authenticated user's own Customer row, so one
 * distributor can never read or mutate another's addresses.
 */
final class AddressController extends Controller
{
    public function __construct(private readonly CustomerAddressService $addresses) {}

    public function index(): View
    {
        $customer = $this->customer();

        return view('shop.addresses.index', [
            'addresses' => $this->addresses->forCustomer($customer->id),
            'presetLabels' => CustomerAddressService::PRESET_LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $customer = $this->customer();

        $this->addresses->save(
            $customer->id,
            $data,
            $data['label'] ?? null,
            (bool) ($request->boolean('is_default')),
        );

        return redirect()->route('addresses.index')->with('status', 'Address saved.');
    }

    public function update(Request $request, CustomerAddress $address): RedirectResponse
    {
        $customer = $this->ownedOr404($address);
        $data = $this->validated($request);

        // Re-save under the (possibly changed) label; the service de-dupes and
        // keeps the single-default invariant.
        $address->update([
            'label' => $data['label'] ?? null,
            'name' => $data['name'],
            'phone_e164' => $data['phone'],
            'line1' => $data['line1'],
            'line2' => $data['line2'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'],
            'pincode' => $data['pincode'],
        ]);

        if ($request->boolean('is_default')) {
            $this->addresses->setDefault($customer->id, $address);
        }

        return redirect()->route('addresses.index')->with('status', 'Address updated.');
    }

    public function setDefault(CustomerAddress $address): RedirectResponse
    {
        $customer = $this->ownedOr404($address);
        $this->addresses->setDefault($customer->id, $address);

        return redirect()->route('addresses.index')->with('status', 'Default address updated.');
    }

    public function destroy(CustomerAddress $address): RedirectResponse
    {
        $customer = $this->ownedOr404($address);
        $this->addresses->delete($customer->id, $address);

        return redirect()->route('addresses.index')->with('status', 'Address removed.');
    }

    /**
     * @return array{label: ?string, name: string, phone: string, line1: string, line2: ?string, city: string, state: string, pincode: string}
     */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:64'],
            'pincode' => ['required', 'regex:/^\d{6}$/'],
        ], [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'pincode.regex' => 'Pincode must be 6 digits.',
        ]);

        $v['phone'] = '+91'.$v['phone'];

        return $v;
    }

    /** The authenticated user's own Customer row, created on first use. */
    private function customer(): Customer
    {
        $user = Auth::user();

        return Customer::firstOrCreate(
            ['user_id' => $user->id],
            [
                'display_name' => $user->full_name ?: 'Customer',
                'distributor_id' => $user->distributor?->id,
                'claimed_at' => now(),
            ],
        );
    }

    /** Confirm the address belongs to the auth user's customer, else 404. */
    private function ownedOr404(CustomerAddress $address): Customer
    {
        $customer = $this->customer();
        abort_unless($address->customer_id === $customer->id, 404);

        return $customer;
    }
}
