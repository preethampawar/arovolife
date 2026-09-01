@extends('errors._layout')

@section('title', 'Something went wrong')
@section('code', '500')
@section('icon')
    <x-lucide-server-crash class="h-10 w-10 text-gray-400" />
@endsection
@section('heading', 'Something went wrong')
@section('message', 'Something went wrong on our side — our team has been notified. Please try again in a few minutes.')
