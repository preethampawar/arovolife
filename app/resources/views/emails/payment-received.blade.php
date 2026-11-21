@extends('emails.layouts.branded', [
    'subject'     => $subjectLine,
    'previewText' => 'We have received your payment for order '.$orderNo.'. Your order is confirmed.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Payment received. Your order is confirmed
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello {{ $buyerName }},
            </p>
            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 24px; color: #374151;">
                We have received your payment for order
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a6660; font-weight: 600;">{{ $orderNo }}</span>.
                Your order is now confirmed. Please keep this email as your payment receipt.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 16px 0; border: 1px solid #e5e7eb; border-radius: 6px;">
                <tr>
                    <td colspan="2" style="padding: 12px 16px; background-color: #f0fdfa; border-bottom: 1px solid #e5e7eb; font-size: 15px; font-weight: 700; color: #0a6660; border-radius: 6px 6px 0 0;">
                        Payment receipt
                    </td>
                </tr>
                @foreach ($receiptLines as $line)
                    <tr>
                        <td style="padding: 9px 16px; font-size: 14px; line-height: 20px; color: #6b7280; border-bottom: 1px solid #f3f4f6;">{{ $line['label'] }}</td>
                        <td align="right" style="padding: 9px 16px; font-size: 14px; line-height: 20px; color: #111827; font-weight: 600; border-bottom: 1px solid #f3f4f6;">{{ $line['value'] }}</td>
                    </tr>
                @endforeach
                @if ($invoiceNo)
                    <tr>
                        <td style="padding: 9px 16px; font-size: 14px; line-height: 20px; color: #6b7280;">Invoice number</td>
                        <td align="right" style="padding: 9px 16px; font-size: 14px; line-height: 20px; color: #111827; font-weight: 600;">{{ $invoiceNo }}</td>
                    </tr>
                @endif
            </table>

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                You can view, print or save the full receipt for this order any time from your account.
            </p>
            <p style="margin: 18px 0 0 0;">
                <a href="{{ $receiptUrl }}" style="display: inline-block; background-color: #0a6660; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600; margin: 0 8px 8px 0;">View receipt</a>
                <a href="{{ $orderUrl }}" style="display: inline-block; background-color: #ffffff; color: #0a6660; text-decoration: none; padding: 9px 17px; border: 1px solid #0a6660; border-radius: 6px; font-size: 14px; font-weight: 600; margin: 0 0 8px 0;">View order</a>
            </p>
        </td>
    </tr>
</table>
@endsection
