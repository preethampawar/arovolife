@extends('emails.layouts.branded', [
    'subject'     => 'Welcome to arovolife — your registration is being reviewed',
    'previewText' => 'Welcome '.$fullName.'. We received your application for ADN '.$adn.' and our compliance team is reviewing your documents.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Welcome to arovolife
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Thank you for joining arovolife. We have received your application and your
                Direct Seller account number is
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>.
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                <strong>What happens next:</strong> our compliance team is reviewing the PAN, Aadhaar, and
                supporting documents you uploaded. Most reviews are completed within one business day.
                You will receive an email as soon as your account is approved.
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Until then your account is in <strong>pending</strong> status — you cannot place orders or
                make introductions yet, but your 30-day cooling-off period has begun and your placement in
                the network is reserved.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 14px 0; border: 1px solid #e5e7eb; background-color: #f9fafb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        <strong>Registration is free of charge.</strong> arovolife will never ask you for a
                        joining fee, security deposit, or product purchase to activate your account.
                    </td>
                </tr>
            </table>

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                Questions? Reply to this email or write to
                <a href="mailto:support@arovolife.com" style="color: #0a719f; text-decoration: underline;">support@arovolife.com</a>.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Welcome aboard,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
