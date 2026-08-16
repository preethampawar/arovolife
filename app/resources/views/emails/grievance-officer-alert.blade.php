{{--
    Internal alert to the officer who owns a grievance. Deliberately carries no
    part of the complaint body — that is read in the admin console, where the
    access is audit-logged.
--}}
@extends('emails.layouts.branded', [
    'subject'      => $headline,
    'previewText'  => $ticketNo.' — '.$ownerLabel.' · resolve by '.$resolutionBy,
    'accentColor'  => '#7c3aed',
    'accentDarker' => '#5b21b6',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                {{ $headline }}
            </p>

            @if ($reason)
                <p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #5b21b6;">
                    {{ $reason }}
                </p>
            @endif

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
                <tr>
                    <td style="padding: 6px 0; color: #6b7280; width: 38%;">Complaint number</td>
                    <td style="padding: 6px 0; color: #111827; font-family: 'SFMono-Regular', Menlo, Consolas, monospace; font-weight: 600;">{{ $ticketNo }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280;">Category</td>
                    <td style="padding: 6px 0; color: #111827;">{{ $categoryLabel }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280;">Now owned by</td>
                    <td style="padding: 6px 0; color: #111827;">{{ $ownerLabel }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #6b7280;">Resolve by</td>
                    <td style="padding: 6px 0; color: #111827;"><strong>{{ $resolutionBy }}</strong></td>
                </tr>
            </table>

            @include('emails.partials.button', [
                'url'   => $adminUrl,
                'label' => 'Open in the admin console',
                'bg'    => '#7c3aed',
                'bgD'   => '#5b21b6',
            ])

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                Nothing the complainant wrote is reproduced here — not the body, not even the subject line.
                Open the ticket to read it; that access is audit-logged, as PII access must be.
            </p>
        </td>
    </tr>
</table>
@endsection
