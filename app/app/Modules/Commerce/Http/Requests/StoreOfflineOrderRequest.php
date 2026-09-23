<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Requests;

use App\Modules\Commerce\Http\Rules\ServiceablePincode;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Services\DTOs\OfflinePaymentDetails;
use App\Modules\Shared\Support\IndianStates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The admin "New offline order" form. Amounts are entered in rupees; the
 * service works in paise. Whether the amount matches the order, the cash
 * ceiling and the duplicate-reference check are the service's, because they
 * need the order and the database, not the request.
 */
final class StoreOfflineOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commerce.order.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference_no' => OfflinePayment::normaliseReference($this->input('reference_no')),
            'adn' => trim((string) $this->input('adn')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $collect = $this->input('delivery_type') === 'collect';
        $cash = $this->input('channel') === OfflinePayment::CHANNEL_CASH;

        return [
            'adn' => ['required', 'digits:9'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'delivery_type' => ['required', Rule::in(['ship', 'collect'])],
            'arete_center_id' => [Rule::requiredIf($collect), 'nullable', 'integer'],
            'buyer_name' => ['required', 'string', 'max:150'],
            'buyer_phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'ship_line1' => [Rule::requiredIf(! $collect), 'nullable', 'string', 'max:255'],
            'ship_line2' => ['nullable', 'string', 'max:255'],
            'ship_city' => [Rule::requiredIf(! $collect), 'nullable', 'string', 'max:100'],
            'ship_state' => [Rule::requiredIf(! $collect), 'nullable', 'string', Rule::in(IndianStates::all())],
            'ship_pincode' => $collect
                ? ['nullable', 'regex:/^\d{6}$/']
                : ['required', 'regex:/^\d{6}$/', app(ServiceablePincode::class)],
            'channel' => ['required', Rule::in(array_keys(OfflinePayment::CHANNELS))],
            'channel_other' => ['required_if:channel,other', 'nullable', 'string', 'max:60'],
            // s.269ST: under ₹2 lakh in cash. The per-day total is the service's.
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:1', $cash ? 'max:199999.99' : 'max:10000000'],
            'received_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(90)->toDateString()],
            'reference_no' => [Rule::requiredIf(! $cash), 'nullable', 'string', 'min:4', 'max:64'],
            'payer_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'terms_acknowledged' => ['accepted'],
            'form_token' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.max' => $this->input('channel') === OfflinePayment::CHANNEL_CASH
                ? 'Cash of ₹2 lakh or more from one person can\'t be accepted (Income Tax Act s.269ST). Ask for a bank transfer or UPI instead.'
                : 'That amount is too large for one order.',
            'reference_no.required' => 'A reference number is needed for every channel except cash (UTR, UPI transaction ID, cheque or receipt no.).',
            'received_on.after_or_equal' => 'Payments older than 90 days cannot be recorded here.',
            'received_on.before_or_equal' => 'The payment date cannot be in the future.',
            'terms_acknowledged.accepted' => 'Confirm the buyer has agreed to the terms of sale.',
            'channel_other.required_if' => 'Say which channel the money came through.',
            'arete_center_id.required' => 'Choose the centre the buyer will collect from.',
            'proof.mimetypes' => 'The proof must be a JPG, PNG or PDF.',
            'proof.max' => 'The proof file must be 5 MB or smaller.',
        ];
    }

    /** @return array<int, int> variant id => quantity, positive quantities only */
    public function lines(): array
    {
        $lines = [];
        foreach ((array) $this->validated('qty') as $variantId => $qty) {
            if ((int) $qty > 0) {
                $lines[(int) $variantId] = (int) $qty;
            }
        }

        return $lines;
    }

    public function paymentDetails(): OfflinePaymentDetails
    {
        $channel = (string) $this->validated('channel');

        return new OfflinePaymentDetails(
            channel: $channel,
            channelOther: $this->validated('channel_other'),
            amountPaise: (int) round((float) $this->validated('amount') * 100),
            receivedOn: CarbonImmutable::parse((string) $this->validated('received_on')),
            referenceNo: $this->validated('reference_no'),
            payerName: $this->validated('payer_name'),
            notes: $this->validated('notes'),
        );
    }

    /**
     * @return array{delivery_type: string, arete_center_id: int|null, name: string, phone: string, line1: string|null, line2: string|null, city: string|null, state: string|null, pincode: string|null}
     */
    public function delivery(): array
    {
        $collect = $this->validated('delivery_type') === 'collect';

        return [
            'delivery_type' => $collect ? 'collect' : 'ship',
            'arete_center_id' => $collect ? (int) $this->validated('arete_center_id') : null,
            'name' => (string) $this->validated('buyer_name'),
            'phone' => '+91'.$this->validated('buyer_phone'),
            'line1' => $this->validated('ship_line1'),
            'line2' => $this->validated('ship_line2'),
            'city' => $this->validated('ship_city'),
            'state' => $this->validated('ship_state'),
            'pincode' => $this->validated('ship_pincode'),
        ];
    }
}
