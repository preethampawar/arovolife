@extends('layouts.app')
@section('title', 'My profile')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">My profile</h1>
    <p class="text-sm text-gray-500 mb-6">Update the details we use to contact you. Your email stays as your sign-in identity.</p>

    @if($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('profile.update') }}" class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5">
        @csrf
        @method('PATCH')

        <div>
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Email (read-only)</label>
            <input type="email" value="{{ $user->email }}" disabled class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-500">
        </div>

        <div>
            <label for="full_name" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Full name</label>
            <input type="text" id="full_name" name="full_name" value="{{ old('full_name', $user->full_name) }}" required
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
        </div>

        <div>
            <label for="phone_e164" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Mobile (+91…)</label>
            <input type="tel" id="phone_e164" name="phone_e164" value="{{ old('phone_e164', $user->phone_e164) }}" required pattern="^\+91[6-9]\d{9}$"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
        </div>

        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('profile.password.show') }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Change password →</a>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold shadow-sm transition-colors">Save changes</button>
        </div>
    </form>
</div>
@endsection
