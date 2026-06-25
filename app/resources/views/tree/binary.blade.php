@extends('layouts.app')
@section('title', 'My Genos')

@section('content')
@include('tree._content', [
    'self'              => $self,
    'childByParentSide' => $childByParentSide,
    'maxDepth'          => $maxDepth,
    'totalDescendants'  => $totalDescendants,
    'maxObservedDepth'  => $maxObservedDepth,
    'mode'              => 'binary',
    'searchUrl'         => route('tree.search'),
    'suggestUrl'        => route('tree.suggest'),
    'rerootBase'        => url('/tree'),
    'rerootKey'         => 'adn',
    'contextNote'       => 'Your Genos is the binary placement tree — everyone you and your downline placed is shown here, split into Left and Right groups. Group BV accumulates daily from purchases made by anyone in each side of your Genos; it feeds your daily 23:59 GSB cut-off. Use the depth filter to navigate large trees level by level.',
])
@endsection
