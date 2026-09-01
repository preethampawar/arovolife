@extends('errors._layout')

@section('title', 'Access denied')
@section('code', '403')
@section('icon')
    <x-lucide-lock class="h-10 w-10 text-gray-400" />
@endsection
@section('heading', 'Access denied')
@section('message', 'You do not have permission to view this page. If you believe this is a mistake, please contact support.')
