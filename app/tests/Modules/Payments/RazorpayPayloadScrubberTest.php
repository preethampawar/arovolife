<?php

declare(strict_types=1);

/**
 * Hard rule 8 / DPDP: nothing personal from the gateway is ever persisted.
 *
 * PAY-S01: drops contact, email, VPA, cardholder name, bank account, tokens
 * PAY-S02: keeps the transactional record and the acquirer refund references
 * PAY-S03: walks a webhook envelope, including the `contains` list
 * PAY-S04: an unknown field is dropped by default (allow-list, not deny-list)
 */

use App\Modules\Payments\Support\RazorpayPayloadScrubber;

/** @return array<string, mixed> */
function razorpayPaymentEntity(): array
{
    return [
        'id' => 'pay_JTVtDcN1uRYb5n', 'entity' => 'payment', 'amount' => 22345, 'currency' => 'INR',
        'status' => 'captured', 'order_id' => 'order_JTVsulofMPyzBY', 'invoice_id' => null,
        'international' => false, 'method' => 'card', 'amount_refunded' => 0, 'refund_status' => null,
        'captured' => true, 'description' => '#JT8o1jsTyzrywc', 'card_id' => 'card_JTVtDjPwZbFbTM',
        'card' => [
            'id' => 'card_JTVtDjPwZbFbTM', 'entity' => 'card', 'name' => 'Gaurav Kumar', 'last4' => '4366',
            'network' => 'Visa', 'type' => 'credit', 'issuer' => 'UTIB', 'international' => false, 'emi' => true,
            'sub_type' => 'consumer', 'token_iin' => null,
        ],
        'bank' => null, 'wallet' => null, 'vpa' => 'gaurav@upi',
        'email' => 'gaurav.kumar@example.com', 'contact' => '+919999999999',
        'customer_id' => 'cust_123', 'token_id' => 'token_abc',
        'notes' => ['arovolife_order_id' => '42', 'arovolife_order_no' => 'ORD-1'],
        'fee' => 0, 'tax' => 0, 'error_code' => null, 'error_description' => null,
        'error_source' => null, 'error_step' => null, 'error_reason' => null,
        'acquirer_data' => ['auth_code' => '472379', 'rrn' => '123456789012', 'arn' => '9999', 'upi_transaction_id' => 'UPI1'],
        'bank_account' => ['ifsc' => 'HDFC0000001', 'name' => 'Gaurav', 'account_number' => '5555444433'],
        'created_at' => 1652183214,
    ];
}

it('PAY-S01: drops every personal field the gateway sends', function () {
    $out = (new RazorpayPayloadScrubber)->scrub(razorpayPaymentEntity());

    expect($out)->not->toHaveKeys(['email', 'contact', 'vpa', 'customer_id', 'token_id', 'bank_account', 'card_id'])
        ->and($out['card'])->not->toHaveKeys(['name', 'id', 'token_iin']);

    // No personal value survives anywhere in the serialised result.
    $json = json_encode($out, JSON_THROW_ON_ERROR);
    foreach (['gaurav@upi', 'gaurav.kumar@example.com', '+919999999999', 'Gaurav Kumar', '5555444433', 'HDFC0000001', 'token_abc', 'cust_123'] as $needle) {
        expect($json)->not->toContain($needle);
    }
});

it('PAY-S02: keeps the transactional record and the refund references', function () {
    $out = (new RazorpayPayloadScrubber)->scrub(razorpayPaymentEntity());

    expect($out)->toMatchArray([
        'id' => 'pay_JTVtDcN1uRYb5n', 'amount' => 22345, 'currency' => 'INR', 'status' => 'captured',
        'order_id' => 'order_JTVsulofMPyzBY', 'method' => 'card', 'captured' => true, 'amount_refunded' => 0,
    ])
        ->and($out['card'])->toMatchArray(['last4' => '4366', 'network' => 'Visa', 'issuer' => 'UTIB', 'type' => 'credit'])
        ->and($out['acquirer_data'])->toMatchArray(['rrn' => '123456789012', 'auth_code' => '472379', 'arn' => '9999', 'upi_transaction_id' => 'UPI1'])
        ->and($out['notes'])->toBe(['arovolife_order_id' => '42', 'arovolife_order_no' => 'ORD-1']);
});

it('PAY-S03: walks a webhook envelope including the contains list', function () {
    $envelope = [
        'entity' => 'event', 'account_id' => 'acc_1', 'event' => 'payment.captured', 'contains' => ['payment'],
        'payload' => ['payment' => ['entity' => razorpayPaymentEntity()]],
        'created_at' => 1652183218,
    ];

    $out = (new RazorpayPayloadScrubber)->scrub($envelope);

    expect($out['event'])->toBe('payment.captured')
        ->and($out['contains'])->toBe(['payment'])
        ->and($out['payload']['payment']['entity']['id'])->toBe('pay_JTVtDcN1uRYb5n')
        ->and($out['payload']['payment']['entity'])->not->toHaveKey('email');
});

it('PAY-S04: a field the allow-list has never heard of is dropped', function () {
    $out = (new RazorpayPayloadScrubber)->scrub(['id' => 'pay_1', 'some_new_field' => 'x', 'nested_new' => ['a' => 1]]);

    expect($out)->toBe(['id' => 'pay_1']);
});
