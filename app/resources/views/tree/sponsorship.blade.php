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
    'contextNote'         => 'This page lists everyone you personally introduced and sponsored — your direct referrals only. You earn a Mentorship Bonus (starting at 10% of their GSB) on each of these distributors\' Genos Sales Bonus income. The deeper Genos placement tree is on the Genos tab.',
])
@endsection
