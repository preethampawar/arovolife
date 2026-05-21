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
    'contextSubtitlePre'  => 'The distributors you directly sponsored — exactly ',
    'showSponsorshipLink' => false,
])
@endsection
