@extends('emails.layouts.branded', [
    'subject'     => 'We received your updated arovolife KYC documents',
    'previewText' => 'Your replacement documents are in the queue. We will review them within one business day.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                We got your updated documents
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $fullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your replacement documents for ADN
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f; font-weight: 600;">{{ $adn }}</span>
                were received on {{ $resubmittedAtFormatted }}.
            </p>

            @if(count($documentTypes) > 0)
            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 14px 0; border: 1px solid #e5e7eb; background-color: #f9fafb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        <strong style="color: #111827;">Replaced documents:</strong>
                        {{ implode(', ', array_map(fn ($t) => ucfirst(str_replace('_', ' ', $t)), $documentTypes)) }}
                    </td>
                </tr>
            </table>
            @endif

            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Your account is back in <strong>pending</strong> status and our compliance team will
                review the new documents within one business day. You'll receive an email as soon as
                a decision is made.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Thanks,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
