@extends('emails.layouts.branded', [
    'subject'     => 'Reset your arovolife password',
    'previewText' => 'Use this link within '.$expiresMinutes.' minutes to set a new password.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Reset your password
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello,
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                We received a request to reset the password on your arovolife account. Click the button below to choose a new password.
            </p>

            @include('emails.partials.button', ['url' => $resetUrl, 'label' => 'Reset my password'])

            <p style="margin: 14px 0 14px 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                This link expires in <strong style="color: #111827;">{{ $expiresMinutes }} minutes</strong>.
                If you didn't request a reset, you can safely ignore this email — your password will stay unchanged.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 13px; line-height: 20px; color: #9ca3af;">
                Trouble with the button? Copy and paste this URL into your browser:<br>
                <a href="{{ $resetUrl }}" style="color: #0a719f; word-break: break-all;">{{ $resetUrl }}</a>
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
