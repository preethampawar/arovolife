@extends('emails.layouts.branded', [
    'subject'     => 'Order '.$orderNo.' is ready to collect',
    'previewText' => 'Your order '.$orderNo.' has arrived at '.$centreName.'.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Ready to collect
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello {{ $buyerName }},
            </p>
            <p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your order
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $orderNo }}</span>
                has arrived and is waiting for you at the centre you chose.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%"
                style="margin: 0 0 22px 0; border: 1px solid #e5e7eb; border-radius: 12px;">
                <tr>
                    <td style="padding: 16px 18px;">
                        <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: #6b7280;">
                            Collect from
                        </p>
                        <p style="margin: 0; font-size: 15px; line-height: 24px; color: #111827; font-weight: 600;">
                            {{ $centreName }}
                        </p>
                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                            {{ $centreAddress }}
                            @if($centrePhone)<br>{{ $centrePhone }}@endif
                        </p>
                    </td>
                </tr>
            </table>

            {{-- The code releases the parcel, so it is set out on its own and
                 said plainly. A buyer who does not realise they need to bring
                 it makes a wasted journey. --}}
            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%"
                style="margin: 0 0 18px 0; background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 12px;">
                <tr>
                    <td style="padding: 18px; text-align: center;">
                        <p style="margin: 0 0 8px 0; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: #6b21a8;">
                            Your collection code
                        </p>
                        <p style="margin: 0; font-family: 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 30px; line-height: 36px; font-weight: 700; letter-spacing: 0.18em; color: #581c87;">
                            {{ $collectionCode }}
                        </p>
                    </td>
                </tr>
            </table>

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Give this code to the centre when you collect. Please keep it to
                yourself — anyone holding it can collect your order.
            </p>
            <p style="margin: 0 0 22px 0; font-size: 14px; line-height: 22px; color: #6b7280;">
                Your 30-day cancellation window starts when you collect the order, not today.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="border-radius: 999px; background: #0a719f;">
                        <a href="{{ $orderUrl }}"
                            style="display: inline-block; padding: 12px 26px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 999px;">
                            View your order
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@endsection
