@extends('emails.layouts.branded', [
    'subject'     => ($isResubmission ? '[arovolife] KYC re-submission received — ADN ' : '[arovolife] New KYC submission — ADN ').$adn,
    'previewText' => ($isResubmission ? 'Re-submission' : 'New submission').' from '.$applicantName.' (ADN '.$adn.') is awaiting your review.',
    'accentColor' => '#4b5563',
    'accentDarker' => '#1f2937',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                @if($isResubmission)
                    KYC re-submission received
                @else
                    New KYC submission to review
                @endif
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hello compliance team,
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                @if($isResubmission)
                    A previously rejected distributor has uploaded replacement documents.
                @else
                    A new distributor has completed registration and is awaiting KYC review.
                @endif
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; border: 1px solid #e5e7eb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151; border-bottom: 1px solid #e5e7eb;">
                        <strong style="color: #111827;">ADN:</strong>
                        <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f;">{{ $adn }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        <strong style="color: #111827;">Applicant:</strong> {{ $applicantName }}
                    </td>
                </tr>
            </table>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" bgcolor="#4b5563" style="border-radius: 6px;">
                        <a href="{{ $reviewUrl }}" class="ar-btn" style="display: inline-block; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            Open review page →
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                This message is sent to every active member of the admin-compliance team. If you handled
                this submission, the others can ignore the notification.
            </p>
        </td>
    </tr>
</table>
@endsection
