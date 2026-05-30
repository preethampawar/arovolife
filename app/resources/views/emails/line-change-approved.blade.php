@extends('emails.layouts.branded', [
    'subject'     => 'Your line-change request was approved',
    'previewText' => 'Your placement (ADN '.$requesterAdn.') has been moved.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Good news — your placement (ADN <strong style="color: #0a719f;">{{ $requesterAdn }}</strong>)
    has been moved under ADN <strong style="color: #0a719f;">{{ $newParentAdn }}</strong>
    on the <strong>{{ $sideLabel }}</strong> group.
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    This changed your <strong>Genos placement only</strong> — your sponsor is unchanged.
</p>
@endsection
