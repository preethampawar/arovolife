@extends('emails.layouts.branded', [
    'subject'     => 'Your arovolife account has been closed',
    'previewText' => 'Important notice: your distributor account '.$adn.' has been closed.',
    'accentColor' => '#374151',
    'accentDarker' => '#111827',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your account has been closed
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                This is to inform you that your arovolife distributor account
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>
                was closed on <strong style="color: #111827;">{{ $terminatedAtFormatted }}</strong>.
                You will not be able to sign in or use the account after this date.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; background-color: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px;">
                <tr>
                    <td style="padding: 14px 16px; font-size: 13px; line-height: 22px; color: #374151;">
                        <strong style="display: block; margin-bottom: 6px; color: #111827;">Reason:</strong>
                        {{ $reason }}
                    </td>
                </tr>
            </table>

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you believe this closure is in error, please reach out to
                <a href="mailto:grievance@arovolife.com" style="color: #1f2937; text-decoration: underline;">grievance@arovolife.com</a>
                within 15 days. Per the Consumer Protection (Direct Selling) Rules, 2021, you have
                the right to a formal review of this decision.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
