@extends('layouts.app')
@section('title', 'My direct referrals')

@section('content')
@php
    // Re-rooted at a downline ADN: these are their direct referrals, not yours.
    $isReRooted = (int) $self->id !== (int) $viewerId;
    $rootName   = $self->user?->full_name ?: ('Distributor '.$self->adn);
@endphp
@include('tree._content', [
    'self'                => $self,
    'viewerId'            => $viewerId,
    'childrenByParent'    => $childrenByParent,
    'maxDepth'            => $maxDepth,
    'totalDescendants'    => $totalDescendants,
    'maxObservedDepth'    => $maxObservedDepth,
    'mode'                => 'sponsorship',
    'contextTitle'        => $isReRooted ? 'Direct referrals of '.$rootName : 'My direct referrals',
    'contextSubtitlePre'  => $isReRooted
        ? 'The distributors '.$rootName.' directly sponsored — exactly '
        : 'The distributors you directly sponsored — exactly ',
    'showSponsorshipLink' => false,
    'contextNote'         => 'This page lists everyone you personally introduced and sponsored — your direct referrals only. You earn a Mentorship Bonus (starting at 10% of their GSB) on each of these distributors\' Genos Sales Bonus income. The deeper Genos placement tree is on the Genos tab.',
])
@endsection
