@extends('emails.layouts.branded', [
    'subject'      => 'Your arovolife cooling-off period ends in '.$daysRemaining.' day'.($daysRemaining === 1 ? '' : 's'),
    'previewText'  => 'Cooling-off ends '.$coolingOffEndsAt.'. Cancel any time from your dashboard.',
    'accentColor'  => '#f5922a',
    'accentDarker' => '#c46e0e',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your cooling-off ends in {{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }}
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello,
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                This is a reminder that your statutory 30-day cooling-off period for arovolife distributor account
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #c46e0e; font-weight: 600;">{{ $adn }}</span>
                ends in <strong>{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }}</strong> — on
                <strong style="color: #111827;">{{ $coolingOffEndsAt }}</strong>.
            </p>
            <p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
                If you wish to cancel your registration, you can do so in one click from your dashboard. No reason required, full refund of any product purchases issued.
            </p>

            @include('emails.partials.button', [
                'url'   => url(route('cooling-off.show', [], false)),
                'label' => 'Cancel registration',
                'bg'    => '#f5922a',
                'bgD'   => '#c46e0e',
            ])

            <p style="margin: 14px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                No action is needed if you are happy to continue.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
