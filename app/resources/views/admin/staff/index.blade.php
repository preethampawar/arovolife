@extends('admin.layouts.admin')
@section('title', 'Staff Users')
@section('heading', 'Staff Users')

@section('content')

<p class="text-sm text-gray-700 mb-6 max-w-3xl">
    Platform team members — every account holding at least one admin role (super admin,
    operations, finance, compliance, and future roles such as KYC reviewers or order
    managers). Staff accounts are not distributors and never appear in the Distributor
    register. Staff provisioning is currently done by the technical team; a create/invite
    flow ships with the multi-operator phase.
</p>

{{-- Role filter pills --}}
<div class="flex items-center gap-3 mb-6 flex-wrap">
    @foreach($roles as $r)
        @php $isActive = request()->query('role') === $r; @endphp
        <a href="{{ route('admin.staff.index', array_merge(request()->query(), ['role' => $r])) }}"
           class="px-3 py-1 rounded-full text-xs font-medium border transition-colors
                  {{ $isActive
                     ? 'bg-brand-500 text-white border-brand-500'
                     : 'bg-white text-gray-700 border-gray-200 hover:border-brand-400' }}">
            {{ $r }}
        </a>
    @endforeach
    @if(request()->query('role'))
    <a href="{{ route('admin.staff.index') }}" class="text-xs text-gray-700 hover:text-gray-900">✕ Clear</a>
    @endif
</div>

{{-- Search --}}
<form method="GET" action="{{ route('admin.staff.index') }}" class="mb-6 flex gap-3">
    @if(request()->query('role'))
        <input type="hidden" name="role" value="{{ request()->query('role') }}">
    @endif
    <input name="q" type="text" value="{{ request()->query('q') }}"
        placeholder="Search name or email…"
        class="flex-1 max-w-sm rounded-lg bg-white border border-gray-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
        Search
    </button>
    @if(request()->query('q'))
    <a href="{{ route('admin.staff.index', array_diff_key(request()->query(), ['q'=>''])) }}"
       class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-sm text-gray-800 hover:text-white transition-colors">
        Clear
    </a>
    @endif
</form>

{{-- Table --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50/50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">#</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Name / Contact</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Roles</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Last Login</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($staff as $u)
                <tr class="hover:bg-white/40 transition-colors">
                    <td class="px-4 py-3 text-gray-600 tabular-nums">{{ ($staff->firstItem() ?? 1) + $loop->index }}</td>
                    <td class="px-4 py-3">
                        <p class="text-gray-800">{{ $u->full_name ?: '—' }}</p>
                        <p class="text-xs text-gray-700">{{ $u->email }}</p>
                        @if(!empty($u->phone_e164))
                            <p class="text-xs text-gray-600 font-mono tracking-tight">{{ $u->phone_e164 }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @foreach($u->roles as $role)
                            <span class="px-2 py-0.5 rounded-full text-xs border bg-indigo-50 text-indigo-700 border-indigo-200">
                                {{ $role->name }}
                            </span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs border
                            {{ $u->status === 'active'     ? 'bg-green-50 text-green-700 border-green-200'
                             : ($u->status === 'frozen'    ? 'bg-red-50 text-red-700 border-red-200'
                             : ($u->status === 'terminated'? 'bg-white text-gray-500 border-gray-200'
                             : 'bg-amber-50 text-amber-700 border-amber-200')) }}">
                            {{ \App\Modules\Identity\Models\User::STATUS_LABELS[$u->status] ?? ucfirst((string) $u->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-700 text-xs">
                        {{ $u->last_login_at?->format('d M Y, h:i A') ?? 'Never' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-600">No staff users found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($staff->hasPages())
<div class="mt-6">
    {{ $staff->links() }}
</div>
@endif

@endsection
