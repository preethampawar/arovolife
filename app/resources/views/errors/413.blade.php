@extends('errors._layout')

@section('title', 'Files too large')
@section('code', '413')
@section('icon')
    <x-lucide-file-warning class="h-10 w-10 text-gray-400" />
@endsection
@section('heading', 'Your files are too large')
@section('message', 'We could not receive your upload because the files you chose are too large together. Each document must be a JPG, PNG or PDF of 5 MB or less. Please go back, choose smaller files — a lower-resolution phone photo or a scanned PDF works well — and try again.')
@section('actions')
    <a href="javascript:history.back()"
       class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
        <x-lucide-arrow-left class="h-4 w-4" />
        Go back and try again
    </a>
@endsection
