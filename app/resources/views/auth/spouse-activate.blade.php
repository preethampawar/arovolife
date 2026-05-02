@extends('layouts.app')
@section('title', 'Activate your arovolife account')

@section('content')

<div class="max-w-md mx-auto py-12">
    <h1 class="text-2xl font-bold mb-2">Activate your account</h1>
    <p class="text-sm text-gray-600 mb-6">
        Welcome, {{ $user->full_name ?? $user->email }}. You've been listed as a co-distributor on
        an arovolife couple registration. Set a password below to activate your account.
    </p>

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('spouse.activate.submit', ['user' => $user->id]) }}{{ '?'.parse_url(request()->fullUrl(), PHP_URL_QUERY) }}"
        class="space-y-4 bg-white rounded-2xl border border-gray-200 p-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">New password</label>
            <input type="password" name="password" required minlength="8" autocomplete="new-password"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
            <p class="mt-1 text-xs text-gray-500">At least 8 characters. Long phrases of unrelated words work best.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Confirm password</label>
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-4 py-2.5 text-sm transition-colors">
            Activate account
        </button>
    </form>
</div>

@endsection
