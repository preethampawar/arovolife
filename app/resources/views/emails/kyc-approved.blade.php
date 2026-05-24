@extends('emails.layouts.branded', [
    'subject'     => 'Welcome — your arovolife account is now active',
    'previewText' => 'Your KYC has been approved. ADN '.$adn.' is now active and ready to use.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                You're approved — welcome aboard
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Great news — your KYC documents were reviewed and approved on
                <strong style="color: #111827;">{{ $approvedAtFormatted }}</strong>.
                Your arovolife distributor account
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>
                is now active.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px;">
                <tr>
                    <td style="padding: 14px 16px; font-size: 14px; line-height: 22px; color: #065f46;">
                        Your 30-day cooling-off window is still running — you can cancel and receive a
                        full refund anytime within that period, no questions asked.
                    </td>
                </tr>
            </table>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" bgcolor="#00b6ef" style="border-radius: 6px;">
                        <a href="{{ $dashboardUrl }}" class="ar-btn" style="display: inline-block; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            Open your dashboard →
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Welcome again,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
