@extends('layouts.app')
@section('title', 'My Genos')

@section('content')
@php
    // Re-rooted at a downline ADN: the canvas is showing their placement,
    // not yours, so the banner must say so.
    $isReRooted = (int) $self->id !== (int) $viewerId;
    $rootName   = $self->user?->full_name ?: ('Distributor '.$self->adn);
@endphp
@include('tree._content', [
    'self'              => $self,
    'viewerId'          => $viewerId,
    'childByParentSide' => $childByParentSide,
    'maxDepth'          => $maxDepth,
    'totalDescendants'  => $totalDescendants,
    'maxObservedDepth'  => $maxObservedDepth,
    'mode'              => 'binary',
    'searchUrl'         => route('tree.search'),
    'suggestUrl'        => route('tree.suggest'),
    'rerootBase'        => url('/tree'),
    'rerootKey'         => 'adn',
    'contextTitle'      => $isReRooted ? 'Genos of '.$rootName : 'My Genos',
    'contextSubtitlePre' => $isReRooted
        ? 'Showing '.$rootName.'’s placement and descendants up to '
        : 'Showing your placement and descendants up to ',
    'contextNote'       => 'Your Genos is your placement tree — everyone you and your downline placed is shown here, split into Left and Right groups. Genos BV accumulates daily from purchases made by anyone in each side of your Genos; it feeds your daily 23:59 GSB cut-off. Use the depth filter to navigate large trees level by level.',
])
@endsection
