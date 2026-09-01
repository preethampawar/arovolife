@extends('errors._layout')

@section('title', 'Too many requests')
@section('code', '429')
@section('icon')
    <x-lucide-hourglass class="h-10 w-10 text-gray-400" />
@endsection
@section('heading', 'Too many requests')
@section('message', 'You are moving a little fast — please wait a moment and try again.')
