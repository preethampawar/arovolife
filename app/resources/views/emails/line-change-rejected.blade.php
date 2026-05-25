@extends('emails.layouts.branded', [
    'subject'     => 'Update on your line-change request',
    'previewText' => 'We were unable to approve your line-change request.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    We've reviewed your line-change request for ADN
    <strong style="color: #0a719f;">{{ $requesterAdn }}</strong> and are unable to approve it at this time.
</p>
<p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Reason:</strong> {{ $decisionNote }}
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    If you have questions, contact support@arovolife.com.
</p>
@endsection
