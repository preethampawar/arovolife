@extends('admin.layouts.admin')
@section('title', 'Delete a partner')
@section('heading', 'Delete a partner')

@section('content')

<div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 max-w-3xl">
    <x-lucide-triangle-alert class="w-4 h-4 shrink-0 mt-px" />
    <div class="space-y-1">
        <p class="font-semibold">This permanently deletes a partner and everyone below them.</p>
        <p>Every partner placed under this APN in the Left and Right Genos is deleted with them. Personal data, KYC documents, BV and ranks are removed. Orders, invoices and wallet rows stay without personal details.</p>
        <p>There is no undo, and no record of the deletion is kept. The next screen lists exactly who will be deleted before anything happens.</p>
    </div>
</div>

<x-ui.card class="max-w-3xl">
    <form method="POST" action="{{ route('admin.distributors.erase.preview') }}" class="space-y-4">
        @csrf
        <label class="block text-xs font-medium text-gray-700">
            APN of the partner to delete
            <input type="text" name="adn" value="{{ old('adn', request('adn')) }}" required inputmode="numeric" maxlength="16" autocomplete="off"
                class="mt-1 block w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm">
        </label>
        @error('adn')
            <p class="text-xs text-red-700">{{ $message }}</p>
        @enderror
        <div class="flex items-center gap-3">
            <x-ui.button>Review who will be deleted</x-ui.button>
            <a href="{{ route('admin.distributors.index') }}" class="text-sm text-gray-700 hover:text-gray-900">Cancel</a>
        </div>
    </form>
</x-ui.card>

@endsection
