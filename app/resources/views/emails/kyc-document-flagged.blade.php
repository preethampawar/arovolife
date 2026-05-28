@extends('emails.layouts.branded', [
    'subject'     => 'Action needed: re-upload your ' . $documentType,
    'previewText' => 'One of your KYC documents needs to be re-uploaded.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    We've reviewed your KYC submission and one document needs to be re-uploaded:
    <strong style="color: #111827;">{{ $documentType }}</strong>.
</p>
<p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Reason:</strong> {{ $reason }}
</p>
<p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Use the secure link below to upload a replacement. You won't need to redo the rest of your KYC.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 18px 0;">
    <tr>
        <td style="border-radius: 8px; background-color: #0a719f;">
            <a href="{{ $reuploadUrl }}"
               style="display: inline-block; padding: 12px 22px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                Re-upload {{ $documentType }} →
            </a>
        </td>
    </tr>
</table>
<p style="margin: 0 0 14px 0; font-size: 12px; line-height: 20px; color: #6b7280;">
    This link is unique to you and expires on <strong>{{ $expiresOn }}</strong>. If you need a new link after that, sign in and visit your dashboard, or reply to this email.
</p>
<p style="margin: 0 0 14px 0; font-size: 13px; line-height: 22px; color: #6b7280;">
    Questions? Email <a href="mailto:support@arovolife.com" style="color: #0a719f;">support@arovolife.com</a>.
</p>
@endsection
