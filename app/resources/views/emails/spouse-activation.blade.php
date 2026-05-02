@extends('emails.layouts.branded', [
    'subject'     => 'Activate your arovolife co-distributor account',
    'previewText' => $primaryFullName.' has listed you as their co-distributor.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Activate your co-distributor account
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello,
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                <strong>{{ $primaryFullName }}</strong> has registered an arovolife distributor account
                (<span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $primaryAdn }}</span>)
                and listed you as their co-distributor.
            </p>
            <p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
                To complete your registration, set a password for your account using the link below.
                The link is valid for <strong>30 days</strong>.
            </p>

            @include('emails.partials.button', ['url' => $url, 'label' => 'Activate my account', 'bg' => '#4fb435', 'bgD' => '#327220'])

            <p style="margin: 14px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you did not expect this email, you can safely ignore it. Your account stays inactive until you set a password.
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
