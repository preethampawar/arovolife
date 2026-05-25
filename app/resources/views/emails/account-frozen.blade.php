@extends('emails.layouts.branded', [
    'subject'     => 'Your arovolife account has been temporarily frozen',
    'previewText' => 'Your distributor account '.$adn.' has been temporarily frozen.',
    'accentColor' => '#b45309',
    'accentDarker' => '#92400e',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your account has been temporarily frozen
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your arovolife distributor account
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>
                was temporarily frozen on <strong style="color: #111827;">{{ $frozenAtFormatted }}</strong>.
                You will not be able to sign in until the account has been reviewed.
            </p>

            @if (! empty($reason))
            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; background-color: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px;">
                <tr>
                    <td style="padding: 14px 16px; font-size: 13px; line-height: 22px; color: #374151;">
                        <strong style="display: block; margin-bottom: 6px; color: #111827;">Reason:</strong>
                        {{ $reason }}
                    </td>
                </tr>
            </table>
            @endif

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you have any questions, please reach out to
                <a href="mailto:support@arovolife.com" style="color: #1f2937; text-decoration: underline;">support@arovolife.com</a>.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
