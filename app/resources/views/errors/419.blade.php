@extends('errors._layout')

@section('title', 'Session expired')
@section('code', '419')
@section('icon')
    <x-lucide-timer-off class="h-10 w-10 text-gray-400" />
@endsection
@section('heading', 'Session expired')
@section('message', 'Your session has expired — please refresh the page and try again.')
