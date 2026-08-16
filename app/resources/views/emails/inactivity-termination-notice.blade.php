@extends('emails.layouts.branded', [
    'subject'      => 'Your arovolife account '.$adn.' will close on '.$noticeExpiresAt,
    'previewText'  => 'One product sale before '.$noticeExpiresAt.' keeps your account open.',
    'accentColor'  => '#dc2626',
    'accentDarker' => '#b91c1c',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your account will close on {{ $noticeExpiresAt }}
            </p>

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                This is the {{ $noticeDays }}-day written notice required by §21 of your Direct Seller
                Agreement.
            </p>

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Our records show no product sale on account
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #b91c1c; font-weight: 600;">{{ $adn }}</span>
                @if ($lastSaleAt)
                    since <strong>{{ $lastSaleAt }}</strong> — more than twelve continuous months.
                @else
                    since you registered — more than twelve continuous months.
                @endif
                Under §21, an account dormant for twelve months is closed.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 18px 0; background: #fef2f2; border-radius: 8px;">
                <tr>
                    <td style="padding: 16px 18px;">
                        <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #b91c1c;">
                            How to keep your account
                        </p>
                        <p style="margin: 0; font-size: 15px; line-height: 24px; color: #111827;">
                            Record <strong>one product sale on or before {{ $noticeExpiresAt }}</strong>.
                            That is all it takes. The notice is withdrawn automatically and nothing about
                            your account, your position or your team changes.
                        </p>
                    </td>
                </tr>
            </table>

            @include('emails.partials.button', [
                'url'   => url(route('dashboard', [], false)),
                'label' => 'Open my dashboard',
                'bg'    => '#dc2626',
                'bgD'   => '#b91c1c',
            ])

            <p style="margin: 18px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                <strong>If the account does close</strong>, you keep every right you already have: any
                balance due to you is paid out, and saleable stock you hold can still be returned under the
                buy-back terms in §8.
            </p>

            <p style="margin: 14px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you believe this notice is wrong — for instance a sale that is not showing against your
                ADN — reply to this email or raise a grievance at
                {{ url(route('grievance.create', [], false)) }} before the date above. A grievance filed
                inside the notice period is looked at before any closure takes effect.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                The arovolife compliance team
            </p>
        </td>
    </tr>
</table>
@endsection
