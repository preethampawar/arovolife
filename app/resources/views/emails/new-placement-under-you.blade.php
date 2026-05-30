@extends('emails.layouts.branded', [
    'subject'     => 'New distributor placed on your '.$sideLabel.' group — ADN '.$newJoinerAdn,
    'previewText' => $newJoinerFullName.' (ADN '.$newJoinerAdn.') has just joined your '.$sideLabel.' group.',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                A new distributor joined your {{ $sideLabel }} group
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Hi {{ $parentFullName }},
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                A new arovolife distributor has been placed directly under your Genos (placement tree) on the
                @if($side === 'L')
                    <strong style="color: #0a719f;">left group ←</strong>
                @else
                    <strong style="color: #0a719f;">right group →</strong>
                @endif
                on {{ $placedAtFormatted }}.
            </p>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="margin: 18px 0; border: 1px solid #e5e7eb; border-radius: 6px;">
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151; border-bottom: 1px solid #e5e7eb;">
                        <strong style="color: #111827;">Your ADN:</strong>
                        <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f;">{{ $parentAdn }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151; border-bottom: 1px solid #e5e7eb;">
                        <strong style="color: #111827;">New joiner:</strong>
                        {{ $newJoinerFullName }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151; border-bottom: 1px solid #e5e7eb;">
                        <strong style="color: #111827;">Their ADN:</strong>
                        <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #0a719f;">{{ $newJoinerAdn }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 14px; font-size: 13px; line-height: 20px; color: #374151;">
                        <strong style="color: #111827;">Group:</strong>
                        {{ $sideLabel === 'left' ? '← Left' : '→ Right' }}
                        <span style="color: #6b7280;">
                            @if($sideChosenBy === 'referral_explicit')
                                (you chose this group)
                            @elseif($sideChosenBy === 'referral_default')
                                (default placement)
                            @elseif($sideChosenBy === 'referral_fallback_right')
                                (left was full — placed right)
                            @endif
                        </span>
                    </td>
                </tr>
            </table>

            <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" bgcolor="#00b6ef" style="border-radius: 6px;">
                        <a href="{{ $treeUrl }}" class="ar-btn" style="display: inline-block; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            View your tree →
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin: 18px 0 0 0; font-size: 13px; line-height: 22px; color: #6b7280;">
                Note: this distributor's KYC is still under admin review. They'll be marked
                <em>active</em> in your tree only after the documents are approved.
            </p>

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
