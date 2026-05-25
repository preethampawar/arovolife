@extends('layouts.app')
@section('title', 'Change password')

@section('content')
<div class="max-w-md mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Change password</h1>
    <p class="text-sm text-gray-500 mb-6">Pick a strong, unique password — we check it against the public breach list.</p>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">Change password</p>
        <p class="leading-relaxed">Set a new password for your arovolife account. You will use it the next time you sign in.</p>
    </div>

    @if($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('profile.password.update') }}" class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5"
        data-confirm="Change your arovolife password?"
        data-confirm-title="Change password"
        data-confirm-impact="Changes your arovolife sign-in password. You'll use the new password next time you sign in.">
        @csrf

        <div>
            <label for="current_password" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Current password <x-help-tip text="enter the password you use to sign in now, so we can confirm it is you." /></label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
        </div>

        <div>
            <label for="new_password" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">New password <x-help-tip text="use at least 8 characters; arovolife rejects passwords found in known public data breaches." /></label>
            <input type="password" id="new_password" name="new_password" required autocomplete="new-password"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
            <p class="mt-1.5 text-xs text-gray-500">Minimum 8 characters; we also block passwords that have appeared in known breaches.</p>
        </div>

        <div>
            <label for="new_password_confirmation" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Confirm new password <x-help-tip text="re-type the new password exactly so we can check the two entries match." /></label>
            <input type="password" id="new_password_confirmation" name="new_password_confirmation" required autocomplete="new-password"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
        </div>

        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('profile.show') }}" class="text-sm text-gray-600 hover:text-gray-800">← Back to profile</a>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold shadow-sm transition-colors">Change password</button>
        </div>
    </form>
</div>
@endsection
