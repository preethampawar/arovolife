@extends('admin.layouts.admin')
@section('title', 'Add Staff User')
@section('heading', 'Add Staff User')

@section('content')

<div class="mb-6">
    <a href="{{ route('admin.staff.index') }}" class="text-sm text-gray-700 hover:text-gray-900">← Back to staff users</a>
</div>

<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900 max-w-2xl">
    <p class="font-semibold mb-1">Create a platform staff account</p>
    <p class="leading-relaxed">
        Staff sign in with their <strong>email address</strong> (distributors sign in with their ADN).
        The account is active immediately and the person can sign in with the password you set here —
        share it with them over a secure channel and ask them to change it. Creation is audit-logged.
    </p>
</div>

<form method="POST" action="{{ route('admin.staff.store') }}" class="max-w-2xl space-y-6"
    data-confirm="Create this staff account?"
    data-confirm-title="Confirm: new staff account"
    data-confirm-impact="Creates a back-office login with the selected roles and immediate access to the admin console. Audit-logged; the account can be deactivated later.">
    @csrf

    <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-4">
        <div>
            <label class="block text-xs text-gray-700 mb-1" for="full_name">
                Full name <x-help-tip text="The team member's name as it should appear in the staff register and audit log." />
            </label>
            <input type="text" id="full_name" name="full_name" required maxlength="150"
                value="{{ old('full_name') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            @error('full_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-xs text-gray-700 mb-1" for="email">
                Email <x-help-tip text="Used as the sign-in identifier for this staff account. Must not already belong to a distributor." />
            </label>
            <input type="email" id="email" name="email" required maxlength="255"
                value="{{ old('email') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-xs text-gray-700 mb-1" for="phone_e164">
                Phone <x-help-tip text="Indian mobile in E.164 format, e.g. +919876543210. Used for account contact, not for sign-in." />
            </label>
            <input type="text" id="phone_e164" name="phone_e164" required maxlength="16" placeholder="+919876543210"
                value="{{ old('phone_e164') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            @error('phone_e164') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="password">
                    Password <x-help-tip text="Minimum 12 characters. Stored only as a one-way hash — nobody, including you, can read it back afterwards." />
                </label>
                <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                @error('password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="12" autocomplete="new-password"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Roles</h3>
        <p class="text-xs text-gray-600 mb-4">
            Pick at least one. Separation of duties (R-17) is enforced in code: finance staff cannot freeze
            accounts and compliance staff cannot record payments.
        </p>
        <div class="space-y-2">
            @foreach($roles as $role)
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="roles[]" value="{{ $role }}" data-field-label="Role: {{ $role }}"
                    @checked(in_array($role, old('roles', []), true))>
                <span class="font-mono text-xs">{{ $role }}</span>
            </label>
            @endforeach
        </div>
        @error('roles') <p class="text-xs text-red-600 mt-2">{{ $message }}</p> @enderror
        @error('roles.*') <p class="text-xs text-red-600 mt-2">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
            Create staff account
        </button>
        <a href="{{ route('admin.staff.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>

@endsection
