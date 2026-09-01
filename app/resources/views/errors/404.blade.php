@extends('errors._layout')

@section('title', 'Page not found')
@section('code', '404')
@section('icon')
    <x-lucide-compass class="h-10 w-10 text-gray-400" />
@endsection
@section('heading', 'Page not found')
@section('message', 'The page you are looking for does not exist or may have been moved.')
