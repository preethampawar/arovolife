@extends('admin.layouts.admin')
@section('title', 'Manage ' . $staff->email)
@section('heading', 'Manage Staff User')

@section('content')

<div class="mb-6">
    <a href="{{ route('admin.staff.index') }}" class="text-sm text-gray-700 hover:text-gray-900">← Back to staff users</a>
</div>

<div class="max-w-2xl space-y-6">

    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-3">{{ $staff->full_name ?: '—' }}</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="text-xs text-gray-600">Email</dt>
                <dd class="text-gray-800">{{ $staff->email }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-600">Phone</dt>
                <dd class="text-gray-800 font-mono text-xs">{{ $staff->phone_e164 ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-600">Status</dt>
                <dd class="text-gray-800">{{ \App\Modules\Identity\Models\User::STATUS_LABELS[$staff->status] ?? ucfirst((string) $staff->status) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-600">Last login</dt>
                <dd class="text-gray-800">{{ $staff->last_login_at?->format('d M Y, h:i A') ?? 'Never' }}</dd>
            </div>
        </dl>
    </div>

    @if($isSelf)
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        This is your own account. Roles and status can only be changed by another staff member —
        a safeguard against locking yourself out of the console.
    </div>
    @else

    {{-- Roles --}}
    <form method="POST" action="{{ route('admin.staff.roles', $staff->id) }}"
        class="bg-white rounded-2xl border border-gray-200 p-6"
        data-confirm="Replace this account's roles?"
        data-confirm-title="Confirm: role change"
        data-confirm-impact="Immediately changes what this person can see and do in the admin console. Audit-logged with the before/after role list.">
        @csrf
        <h3 class="font-semibold text-gray-800 mb-1">Roles</h3>
        <p class="text-xs text-gray-600 mb-4">Ticked roles replace the current set. At least one is required.</p>
        <div class="space-y-2 mb-4">
            @foreach($roles as $role)
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="roles[]" value="{{ $role }}" data-field-label="Role: {{ $role }}"
                    @checked(in_array($role, old('roles', $assigned), true))>
                <span class="font-mono text-xs">{{ $role }}</span>
            </label>
            @endforeach
        </div>
        @error('roles') <p class="text-xs text-red-600 mb-3">{{ $message }}</p> @enderror
        @error('roles.*') <p class="text-xs text-red-600 mb-3">{{ $message }}</p> @enderror
        <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
            Save roles
        </button>
    </form>

    {{-- Status --}}
    <form method="POST" action="{{ route('admin.staff.status', $staff->id) }}"
        class="bg-white rounded-2xl border border-gray-200 p-6"
        data-confirm="{{ $staff->status === 'frozen' ? 'Reactivate this staff account?' : 'Deactivate this staff account?' }}"
        data-confirm-title="Confirm: account status"
        data-confirm-impact="{{ $staff->status === 'frozen'
            ? 'Restores this person\'s ability to sign in to the admin console. Audit-logged.'
            : 'Blocks this person from signing in immediately. Their audit history is retained and the account can be reactivated later.' }}">
        @csrf
        <h3 class="font-semibold text-gray-800 mb-1">Account status</h3>
        <p class="text-xs text-gray-600 mb-4">
            Deactivating blocks sign-in without deleting anything — the audit trail stays intact.
        </p>
        <input type="hidden" name="status" value="{{ $staff->status === 'frozen' ? 'active' : 'frozen' }}">
        @error('status') <p class="text-xs text-red-600 mb-3">{{ $message }}</p> @enderror
        <button type="submit"
            class="px-4 py-2 rounded-lg text-sm font-medium text-white {{ $staff->status === 'frozen' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700' }}">
            {{ $staff->status === 'frozen' ? 'Reactivate account' : 'Deactivate account' }}
        </button>
    </form>

    @endif
</div>

@endsection
