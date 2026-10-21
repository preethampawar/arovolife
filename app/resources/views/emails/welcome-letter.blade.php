@extends('emails.layouts.branded', [
    'subject'     => 'Welcome to the arovolife family',
    'previewText' => 'Welcome '.$fullName.'. A warm welcome from all of us at arovolife.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            @include('emails.partials.welcome-letter')

            <p style="margin: 0 0 14px 0; padding-top: 18px; border-top: 1px solid #e5e7eb; font-size: 15px; line-height: 24px; color: #374151;">
                Your Direct Seller account number is
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>.
                A separate email has a link to set your password and sign in.
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
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

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                Questions? Reply to this email or write to
                <a href="mailto:support@arovolife.com" style="color: #0a719f; text-decoration: underline;">support@arovolife.com</a>.
            </p>

            @include('emails.partials.welcome-letter-signoff')
        </td>
    </tr>
</table>
@endsection
