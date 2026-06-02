@extends('emails.layouts.branded', [
    'subject'     => 'Order '.$orderNo.' received',
    'previewText' => 'We have received your order '.$orderNo.'.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Thank you for your order
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello {{ $buyerName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                We've received your order
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $orderNo }}</span>.
                The order total is <strong style="color: #111827;">{{ $totalFormatted }}</strong>.
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                You can view your order any time from your account.
            </p>
            <p style="margin: 18px 0 0 0;">
                <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #0a719f; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600;">View order</a>
            </p>
        </td>
    </tr>
</table>
@endsection
