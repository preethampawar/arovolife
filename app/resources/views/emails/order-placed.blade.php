@php
    $isPaid = $paymentState === \App\Modules\Commerce\Notifications\OrderPlacedNotification::PAYMENT_PAID;
    $isOffline = $paymentState === \App\Modules\Commerce\Notifications\OrderPlacedNotification::PAYMENT_AWAITING_OFFLINE;
@endphp
@extends('emails.layouts.branded', [
    'subject'     => $subjectLine,
    'previewText' => $isPaid
        ? 'We have received your payment for order '.$orderNo.'. Your order is confirmed.'
        : 'Your order '.$orderNo.' is placed but not paid yet. It is confirmed only after payment.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                {{ $isPaid ? 'Your order is confirmed' : 'Your order is waiting for payment' }}
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello {{ $buyerName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                We've received your order
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a6660; font-weight: 600;">{{ $orderNo }}</span>.
                The order total is <strong style="color: #111827;">{{ $totalFormatted }}</strong>.
            </p>

            @if ($isPaid)
                <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                    We have also received your payment, so your order is confirmed.
                </p>
            @else
                <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 16px 0;">
                    <tr>
                        <td style="background-color: #fff7ed; border: 1px solid #fdba74; border-radius: 6px; padding: 14px 16px;">
                            <p style="margin: 0 0 6px 0; font-size: 15px; line-height: 22px; font-weight: 700; color: #9a3412;">
                                Payment pending: your order is not confirmed yet
                            </p>
                            @if ($isOffline)
                                <p style="margin: 0; font-size: 14px; line-height: 22px; color: #7c2d12;">
                                    We have not received your payment for this order yet. Your order will be confirmed once our team records your payment.
                                </p>
                            @else
                                <p style="margin: 0; font-size: 14px; line-height: 22px; color: #7c2d12;">
                                    We have not received your payment for this order yet. Your order will be confirmed only after the payment is complete.
                                    @if ($payByLabel)
                                        Please pay by <strong>{{ $payByLabel }}</strong> (IST). If we do not receive the payment by then, the order is cancelled automatically.
                                    @endif
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
                @unless ($isOffline)
                    <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                        If you have already paid, you do not need to pay again. It can take a few minutes for the payment to show on your order. If money has left your account but the order still shows payment pending after an hour, please contact us with your order number.
                    </p>
                @endunless
            @endif

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                You can check your order and its payment status any time from your account.
            </p>
            <p style="margin: 18px 0 0 0;">
                @if (! $isPaid && ! $isOffline)
                    <a href="{{ $payUrl }}" style="display: inline-block; background-color: #0a6660; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600; margin: 0 8px 8px 0;">Complete payment</a>
                    <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #ffffff; color: #0a6660; text-decoration: none; padding: 9px 17px; border: 1px solid #0a6660; border-radius: 6px; font-size: 14px; font-weight: 600; margin: 0 0 8px 0;">View order</a>
                @else
                    <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #0a6660; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600;">View order</a>
                @endif
            </p>
        </td>
    </tr>
</table>
@endsection
