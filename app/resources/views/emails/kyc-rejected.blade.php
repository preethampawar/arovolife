@extends('emails.layouts.branded', [
    'subject'     => 'Action needed — your arovolife KYC submission needs updates',
    'previewText' => 'Your KYC submission needs updates. Sign in and re-upload your documents.',
    'accentColor' => '#dc2626',
    'accentDarker' => '#991b1b',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your documents need a small fix
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Our compliance team reviewed your submission for ADN
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>
                on {{ $rejectedAtFormatted }} and asked for an update before we can approve you.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px;">
                <tr>
                    <td style="padding: 14px 16px; font-size: 13px; line-height: 22px; color: #7f1d1d;">
                        <strong style="display: block; margin-bottom: 6px; color: #991b1b;">Reviewer's note:</strong>
                        {{ $reason }}
                    </td>
                </tr>
            </table>

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your account hasn't been closed — please sign in, re-upload the affected documents on
                the resubmission page, and your application will go straight back into the review queue.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" bgcolor="#dc2626" style="border-radius: 6px;">
                        <a href="{{ $resubmitUrl }}" class="ar-btn" style="display: inline-block; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            Re-upload my documents →
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                Trouble signing in or unsure what to upload? Reply to this email or write to
                <a href="mailto:support@arovolife.com" style="color: #991b1b; text-decoration: underline;">support@arovolife.com</a>
                and we'll help.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
