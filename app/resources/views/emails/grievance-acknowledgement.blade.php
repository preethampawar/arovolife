@extends('emails.layouts.branded', [
    'subject'      => 'We have registered your grievance — '.$ticketNo,
    'previewText'  => 'Your complaint number is '.$ticketNo.'. Keep it for all correspondence.',
    'accentColor'  => '#2563eb',
    'accentDarker' => '#1d4ed8',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                We have registered your grievance
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Thank you for writing to us. Your complaint has been recorded and assigned a complaint number.
                Please quote this number in every follow-up.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 18px 0; background: #eff6ff; border-radius: 8px;">
                <tr>
                    <td style="padding: 16px 18px;">
                        <p style="margin: 0 0 4px 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #1e40af;">
                            Your complaint number
                        </p>
                        <p style="margin: 0; font-family: 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 20px; font-weight: 700; color: #1d4ed8;">
                            {{ $ticketNo }}
                        </p>
                    </td>
                </tr>
            </table>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; width: 42%;">What you raised</td>
                    <td style="padding: 6px 0; color: #111827;">{{ $ticketSubject }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280;">Category</td>
                    <td style="padding: 6px 0; color: #111827;">{{ $categoryLabel }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280;">First substantive response by</td>
                    <td style="padding: 6px 0; color: #111827;"><strong>{{ $firstResponseBy }}</strong></td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280;">Resolution by</td>
                    <td style="padding: 6px 0; color: #111827;"><strong>{{ $resolutionBy }}</strong></td>
                </tr>
            </table>

            <p style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
                If the matter needs a bank, a payment gateway or a statutory authority to respond,
                resolution can take up to 60 days. In that case we will write to you with a progress
                update at least every 15 days.
            </p>

            @include('emails.partials.button', [
                'url'   => url(route('grievance.track', [], false)),
                'label' => 'Track this grievance',
                'bg'    => '#2563eb',
                'bgD'   => '#1d4ed8',
            ])

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                If you are not satisfied with our response at any stage, the full escalation route —
                including the National Consumer Helpline and the Central Consumer Protection Authority —
                is published at {{ url(route('content.show', 'grievance', false)) }}.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                The arovolife grievance team
            </p>
        </td>
    </tr>
</table>
@endsection
