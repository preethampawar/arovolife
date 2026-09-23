@extends('emails.layouts.branded', [
    'subject'     => 'Refund for order '.$orderNo.' has been processed',
    'previewText' => 'Your refund of '.$amount.' for order '.$orderNo.' has been processed.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Refund processed
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello {{ $buyerName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your refund of <strong style="color: #111827;">{{ $amount }}</strong> for order
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $orderNo }}</span>
                was paid {{ $methodLabel }} on {{ $settledAt }}.
            </p>
            @if ($reference)
                <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                    Reference:
                    <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #111827;">{{ $reference }}</span>
                </p>
            @endif
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Banks can take a few working days to show the credit in your account.
            </p>
            <p style="margin: 18px 0 0 0;">
                <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #0a719f; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600;">View order</a>
            </p>
        </td>
    </tr>
</table>
@endsection
