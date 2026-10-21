@extends('emails.layouts.branded', [
    'subject'     => 'Activate your arovolife account',
    'previewText' => 'Your arovolife Direct Seller account '.$adn.' is ready. Set a password to activate it.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Activate your account
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Dear {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                The arovolife team has registered your Direct Seller account. Your arovolife Distributor
                Number (ADN) is
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>.
            </p>
            <p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
                To start using your account, set a password using the link below.
                The link is valid for <strong>30 days</strong>.
            </p>

            @include('emails.partials.button', ['url' => $url, 'label' => 'Activate my account', 'bg' => '#4fb435', 'bgD' => '#327220'])

            <p style="margin: 18px 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your 30-day cooling-off period has begun. If you change your mind, you can cancel at any
                time within those 30 days by signing in or by writing to support@arovolife.com, and any
                product you bought is refunded in full. This is your cooling-off period under the
                Consumer Protection (Direct Selling) Rules, 2021.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 14px 0; border: 1px solid #e5e7eb; background-color: #f9fafb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        <strong>Registration is free of charge.</strong> arovolife will never ask you for a
                        joining fee, security deposit, or product purchase to activate your account.
                    </td>
                </tr>
            </table>

            <p style="margin: 14px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you did not ask to join arovolife, please write to
                <a href="mailto:support@arovolife.com" style="color: #0a719f; text-decoration: underline;">support@arovolife.com</a>
                and we will close the account. No one can sign in to it until a password is set.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 13px; line-height: 20px; color: #9ca3af;">
                Trouble with the button? Copy and paste this URL into your browser:<br>
                <a href="{{ $url }}" style="color: #327220; word-break: break-all;">{{ $url }}</a>
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
