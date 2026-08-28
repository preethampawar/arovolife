@extends('emails.layouts.branded', [
    'subject'      => 'Your grievance '.$ticketNo.' has been resolved',
    'previewText'  => 'Here is how we resolved complaint '.$ticketNo.'.',
    'accentColor'  => '#059669',
    'accentDarker' => '#047857',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your grievance has been resolved
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Complaint
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #047857; font-weight: 600;">{{ $ticketNo }}</span>
                — {{ $ticketSubject }}
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 18px 0; background: #ecfdf5; border-radius: 8px;">
                <tr>
                    <td style="padding: 16px 18px;">
                        <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #047857;">
                            What we did
                        </p>
                        <p style="margin: 0; font-size: 15px; line-height: 24px; color: #111827;">
                            {{ $resolutionNote }}
                        </p>
                    </td>
                </tr>
            </table>

            <p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
                <strong>If this does not settle the matter for you</strong>, you may escalate to the
                {{ $escalationLabel }} at
                <a href="mailto:{{ $escalationContact }}" style="color: #047857;">{{ $escalationContact }}</a>,
                quoting your complaint number.
            </p>

            <p style="margin: 0 0 18px 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                You may also approach the National Consumer Helpline (1800-11-4000 / 1915), the Central
                Consumer Protection Authority, or a Consumer Disputes Redressal Commission directly. You do
                not need to exhaust our internal steps first. The full route is published at
                {{ url(route('content.show', 'grievance', false)) }}.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                The arovolife grievance team
            </p>
        </td>
    </tr>
</table>
@endsection
