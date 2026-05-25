@extends('emails.layouts.branded', [
    'subject'     => 'Line-change request to review — ADN '.$requesterAdn,
    'previewText' => 'Distributor '.$requesterAdn.' requested a binary-placement change.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Distributor <strong style="color: #0a719f;">{{ $requesterAdn }}</strong> has requested a line change.
</p>
<p style="margin: 0 0 8px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Requested target placement parent:</strong> {{ $targetParentAdn }}
</p>
<p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Reason given:</strong> {{ $reason ?: '—' }}
</p>
<p style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
    This will move the requester's <strong>binary placement only</strong>; their sponsor is unchanged.
</p>
<p style="margin: 0 0 14px 0;">
    <a href="{{ $reviewUrl }}" style="display: inline-block; padding: 10px 18px; background: #0a719f; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 6px;">
        Review request
    </a>
</p>
@endsection
