@extends('layouts.app')
@section('title', 'My binary tree')

@section('content')
@include('tree._content', [
    'self'              => $self,
    'childByParentSide' => $childByParentSide,
    'maxDepth'          => $maxDepth,
    'totalDescendants'  => $totalDescendants,
    'maxObservedDepth'  => $maxObservedDepth,
    'mode'              => 'binary',
    'searchUrl'         => route('tree.search'),
    'rerootBase'        => url('/tree'),
    'rerootKey'         => 'adn',
])
@endsection
