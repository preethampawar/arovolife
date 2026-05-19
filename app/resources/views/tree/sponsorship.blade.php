@extends('layouts.app')
@section('title', 'My direct referrals')

@section('content')
@include('tree._content', [
    'self'                => $self,
    'childrenByParent'    => $childrenByParent,
    'maxDepth'            => $maxDepth,
    'totalDescendants'    => $totalDescendants,
    'maxObservedDepth'    => $maxObservedDepth,
    'mode'                => 'sponsorship',
    'contextTitle'        => 'My direct referrals',
    'contextSubtitlePre'  => 'Showing the distributors you directly introduced (and theirs) up to ',
    'showSponsorshipLink' => false,
])
@endsection
