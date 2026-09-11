@extends('emails.layouts.branded', [
    'subject'     => 'Your arovolife bank details were updated',
    'previewText' => 'The bank account we pay your income into has changed.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    The bank account arovolife pays your income into has been updated.
</p>
<p style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Account:</strong> ••••{{ $accountLast4 }}<br>
    <strong style="color: #111827;">IFSC:</strong> {{ $ifsc }}
</p>
<p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Your next payout will go to this account. Nothing already transferred is affected.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 18px 0;">
    <tr>
        <td style="border-radius: 8px; background-color: #0a719f;">
            <a href="{{ $bankUrl }}"
               style="display: inline-block; padding: 12px 22px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                Review your bank details →
            </a>
        </td>
    </tr>
</table>
<p style="margin: 0 0 14px 0; font-size: 13px; line-height: 22px; color: #6b7280;">
    <strong style="color: #111827;">If this wasn't you</strong>, change your password immediately and email
    <a href="mailto:support@arovolife.com" style="color: #0a719f;">support@arovolife.com</a> so we can hold your payouts while we check.
</p>
@endsection
