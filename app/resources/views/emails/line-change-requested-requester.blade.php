@extends('emails.layouts.branded', [
    'subject'     => 'We received your line-change request',
    'previewText' => 'Your line-change request for ADN '.$requesterAdn.' is now with our team.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Thanks — we've received your line-change request for ADN
    <strong style="color: #0a719f;">{{ $requesterAdn }}</strong>.
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    You asked to move your binary-tree placement under ADN
    <strong style="color: #0a719f;">{{ $targetParentAdn }}</strong>. This changes your
    <strong>binary placement only</strong> — your sponsor stays the same.
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    An admin will review it shortly. We'll email you again once a decision is made.
</p>
@endsection
