@extends('emails.layouts.branded', [
    'subject'     => 'Your arovolife registration has been cancelled',
    'previewText' => 'Confirmation that distributor account '.$adn.' has been cancelled on '.$cancelledAtFormatted.'.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Registration cancelled
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello,
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                This message confirms that your distributor account
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>
                has been cancelled on <strong style="color: #111827;">{{ $cancelledAtFormatted }}</strong>.
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your account is now closed. You will not receive further reminders or correspondence about this registration.
            </p>

            @if($cascaded)
            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 14px 0; border: 1px solid #e5e7eb; background-color: #f9fafb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        Per the Direct Seller Agreement &sect;7, your spouse's linked account has also been closed as part of the same cancellation.
                    </td>
                </tr>
            </table>
            @endif

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you did not request this cancellation, please reach out to
                <a href="mailto:support@arovolife.com" style="color: #0a719f; text-decoration: underline;">support@arovolife.com</a>
                immediately.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
