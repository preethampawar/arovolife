@extends('emails.layouts.branded', [
    'subject'      => 'Progress update on your grievance '.$ticketNo,
    'previewText'  => 'Where complaint '.$ticketNo.' currently stands.',
    'accentColor'  => '#1e4952',
    'accentDarker' => '#a54a27',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your grievance is still with us
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Complaint
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #833c22; font-weight: 600;">{{ $ticketNo }}</span>
                — {{ $ticketSubject }}
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 18px 0; background: #fff7ed; border-radius: 8px;">
                <tr>
                    <td style="padding: 16px 18px;">
                        <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #833c22;">
                            Where it stands
                        </p>
                        <p style="margin: 0; font-size: 15px; line-height: 24px; color: #111827;">
                            {{ $updateNote }}
                        </p>
                    </td>
                </tr>
            </table>

            <p style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
                We expect to resolve this by <strong>{{ $resolutionBy }}</strong>. While we wait on a third
                party, you will hear from us at least every 15 days.
            </p>

            @include('emails.partials.button', [
                'url'   => url(route('grievance.track', [], false)),
                'label' => 'Track this grievance',
                'bg'    => '#a54a27',
                'bgD'   => '#833c22',
            ])

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                The arovolife grievance team
            </p>
        </td>
    </tr>
</table>
@endsection
