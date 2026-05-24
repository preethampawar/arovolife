@extends('emails.layouts.branded', [
    'subject'     => 'New direct referral — ADN '.$referralAdn,
    'previewText' => $referralFullName.' (ADN '.$referralAdn.') has registered as your direct referral.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                You have a new direct referral
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $sponsorFullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Great news — someone you invited has just registered with arovolife on
                {{ $registeredAtFormatted }}. They will appear in your direct-referrals list
                under your sponsorship tree.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; border: 1px solid #e5e7eb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151; border-bottom: 1px solid #e5e7eb;">
                        <strong style="color: #111827;">Your ADN:</strong>
                        <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f;">{{ $sponsorAdn }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151; border-bottom: 1px solid #e5e7eb;">
                        <strong style="color: #111827;">Referral:</strong>
                        {{ $referralFullName }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        <strong style="color: #111827;">Their ADN:</strong>
                        <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f;">{{ $referralAdn }}</span>
                    </td>
                </tr>
            </table>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" bgcolor="#00b6ef" style="border-radius: 6px;">
                        <a href="{{ $referralsUrl }}" class="ar-btn" style="display: inline-block; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            View my referrals →
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                This referral's KYC is still under admin review and you'll see the
                <em>active</em> status in your tree only after their documents are approved.
                Per the Direct Selling Rules 2021, referral credit accrues only on product
                sales — not on registrations.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
