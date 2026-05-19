@extends('admin.layouts.admin')
@section('title', $distributor->adn)
@section('heading', 'Distributor: ' . $distributor->adn)

@section('content')

<div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.distributors.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to Distributors</a>

    <div class="flex items-center gap-2">
        <a href="{{ route('admin.tree.show', $distributor->id) }}"
           class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-brand-300 bg-white hover:bg-brand-50 text-brand-700 text-xs font-semibold transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3"/></svg>
            View tree
        </a>

        @if(auth()->id() !== (int) $distributor->user_id)
            <form method="POST" action="{{ route('admin.impersonate.start', $distributor->user_id) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sunrise-500 hover:bg-sunrise-600 text-white text-xs font-semibold transition-colors shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                    Impersonate
                </button>
            </form>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    {{-- Identity Card --}}
    <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 p-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <p class="text-3xl font-mono font-bold text-brand-600">{{ $distributor->adn }}</p>
                <p class="text-sm text-gray-600 mt-1">{{ $distributor->full_name ?: 'No name recorded' }}</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="px-3 py-1 rounded-full text-sm border
                    {{ $distributor->status === 'active'     ? 'bg-green-50 text-green-700 border-green-200'
                     : ($distributor->status === 'frozen'    ? 'bg-red-50 text-red-700 border-red-200'
                     : ($distributor->status === 'terminated'? 'bg-white text-gray-500 border-gray-200'
                     : 'bg-amber-50 text-amber-700 border-amber-200')) }}">
                    {{ ucfirst($distributor->status) }}
                </span>
                <span class="px-3 py-1 rounded-full text-xs border
                    {{ $distributor->distributor_status === 'active'
                        ? 'bg-green-50 text-green-700 border-green-200'
                        : 'bg-gray-100 text-gray-600 border-gray-200' }}"
                    title="Distributor record status (distributors.status)">
                    Distributor: {{ ucfirst($distributor->distributor_status) }}
                </span>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><p class="text-xs text-gray-500 mb-0.5">Email</p><p class="text-gray-800">{{ $distributor->email }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">Phone</p><p class="text-gray-800">{{ $distributor->phone_e164 }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">State</p><p class="text-gray-800">{{ $distributor->state }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">Date of Birth</p><p class="text-gray-800">{{ $distributor->date_of_birth ?? '—' }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">PAN (last 4)</p><p class="text-gray-800 font-mono">XXXXXX{{ $distributor->pan_last4 }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">Aadhaar (last 4)</p><p class="text-gray-800 font-mono">XXXXXXXX{{ $distributor->aadhaar_last4 ?? '—' }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">Bank IFSC</p><p class="text-gray-800 font-mono">{{ $distributor->bank_ifsc }}</p></div>
            <div><p class="text-xs text-gray-500 mb-0.5">Registered</p><p class="text-gray-800">{{ \Carbon\Carbon::parse($distributor->user_created_at)->format('d M Y') }}</p></div>
        </div>
    </div>

    {{-- Placement Card --}}
    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Placement</p>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-600">Depth</span>
                    <span class="font-medium">Level {{ $distributor->depth }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Side</span>
                    <span class="font-medium">{{ $distributor->placement_side === 'L' ? '← Left' : ($distributor->placement_side === 'R' ? '→ Right' : 'Root') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Chosen by</span>
                    <span class="font-mono text-xs text-gray-700">{{ $distributor->side_chosen_by }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Downline total</span>
                    <span class="font-medium">{{ $downlineCount }}</span>
                </div>
            </div>
        </div>

        {{-- Binary Children --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Direct Children</p>
            <div class="flex gap-3">
                <div class="flex-1 rounded-lg p-3 border {{ $leftChild ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white/50' }}">
                    <p class="text-xs text-gray-500 mb-1">Left (L)</p>
                    @if($leftChild)
                    <a href="{{ route('admin.distributors.show', $leftChild->id) }}" class="text-xs font-mono text-brand-600 hover:underline">{{ $leftChild->adn }}</a>
                    @else
                    <span class="text-xs text-gray-400">Empty</span>
                    @endif
                </div>
                <div class="flex-1 rounded-lg p-3 border {{ $rightChild ? 'border-blue-700 bg-blue-900/10' : 'border-gray-200 bg-white/50' }}">
                    <p class="text-xs text-gray-500 mb-1">Right (R)</p>
                    @if($rightChild)
                    <a href="{{ route('admin.distributors.show', $rightChild->id) }}" class="text-xs font-mono text-brand-700 hover:underline">{{ $rightChild->adn }}</a>
                    @else
                    <span class="text-xs text-gray-400">Empty</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Referral link generator (admin can hand a link to a prospect) --}}
        @php $inviteAuto = url('/register').'?sponsor='.$distributor->adn.'&placement='.$distributor->adn; @endphp
        <div class="bg-white rounded-2xl border border-brand-200 p-5">
            <p class="text-xs text-brand-700 uppercase tracking-wider mb-2 font-semibold">Referral link for this distributor</p>
            <p class="text-xs text-gray-600 mb-2">Give this URL to anyone they're inviting; the new joiner will be sponsored by — and placed under — this distributor.</p>
            <div class="flex items-stretch gap-2">
                <input type="text" readonly value="{{ $inviteAuto }}"
                    class="flex-1 rounded-lg border border-gray-300 bg-gray-50 px-2 py-1.5 text-[11px] font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    onclick="this.select()">
                <button type="button"
                    onclick="navigator.clipboard.writeText('{{ $inviteAuto }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
                    class="px-3 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition-colors">
                    Copy
                </button>
            </div>
        </div>

        {{-- Cooling-Off --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Cooling-Off</p>
            @php $daysLeft = now()->diffInDays($distributor->cooling_off_end_at, false); @endphp
            <p class="text-sm">
                Ends: <span class="font-medium">{{ \Carbon\Carbon::parse($distributor->cooling_off_end_at)->format('d M Y') }}</span>
            </p>
            @if($daysLeft > 0)
            <p class="text-xs {{ $daysLeft <= 7 ? 'text-red-700' : 'text-amber-700' }} mt-1">
                {{ (int)$daysLeft }} days remaining
            </p>
            @else
            <p class="text-xs text-gray-400 mt-1">Period expired</p>
            @endif
        </div>
    </div>
</div>

{{-- Sponsor --}}
@if($sponsor)
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
    <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Sponsor</p>
    <p class="text-sm font-mono text-brand-600">{{ $sponsor->adn }}</p>
    <p class="text-xs text-gray-500">{{ $sponsor->full_name }} · {{ $sponsor->email }}</p>
</div>
@endif

{{-- Admin Actions --}}
@if($distributor->status !== 'terminated')
<div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
    <h3 class="font-semibold text-gray-800 mb-4">Account Actions</h3>
    <div class="flex flex-wrap gap-4">

        @if($distributor->status === 'frozen')
        <form method="POST" action="{{ route('admin.distributors.unfreeze', $distributor->id) }}">
            @csrf
            <button type="submit"
                class="px-4 py-2 rounded-lg bg-green-700 hover:bg-green-600 text-white text-sm font-medium transition-colors">
                ✓ Unfreeze Account
            </button>
        </form>
        @endif

        @if(in_array($distributor->status, ['active','pending']))
        <button onclick="document.getElementById('freeze-form').classList.toggle('hidden')"
            class="px-4 py-2 rounded-lg bg-yellow-700 hover:bg-yellow-600 text-white text-sm font-medium transition-colors">
            ⚠ Freeze Account
        </button>
        @endif

        <button onclick="document.getElementById('terminate-form').classList.toggle('hidden')"
            class="px-4 py-2 rounded-lg bg-red-800 hover:bg-red-700 text-white text-sm font-medium transition-colors">
            ✕ Terminate
        </button>

        @if($distributor->distributor_status === 'active')
        <form method="POST" action="{{ route('admin.distributors.deactivate', $distributor->id) }}">
            @csrf
            <button type="submit"
                class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium transition-colors"
                title="Mark distributor record inactive (distributors.status = inactive)">
                ⏸ Deactivate Distributor
            </button>
        </form>
        @else
        <form method="POST" action="{{ route('admin.distributors.activate', $distributor->id) }}">
            @csrf
            <button type="submit"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors"
                title="Mark distributor record active (distributors.status = active)">
                ▶ Activate Distributor
            </button>
        </form>
        @endif
    </div>

    <form id="freeze-form" method="POST"
        action="{{ route('admin.distributors.freeze', $distributor->id) }}"
        class="hidden mt-4 space-y-3 border-t border-gray-200 pt-4">
        @csrf
        <label class="block text-sm text-gray-700">Reason for freezing <span class="text-red-700">*</span></label>
        <textarea name="reason" required rows="2" placeholder="Enter reason…"
            class="w-full max-w-lg rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500 resize-none"></textarea>
        <button type="submit" class="px-4 py-2 rounded-lg bg-yellow-700 hover:bg-yellow-600 text-white text-sm font-medium transition-colors">
            Confirm Freeze
        </button>
    </form>

    <form id="terminate-form" method="POST"
        action="{{ route('admin.distributors.terminate', $distributor->id) }}"
        class="hidden mt-4 space-y-3 border-t border-gray-200 pt-4">
        @csrf
        <p class="text-sm text-red-700 font-medium">⚠ This action is irreversible. The distributor's ADN will be frozen.</p>
        <label class="block text-sm text-gray-700">Reason for termination <span class="text-red-700">*</span></label>
        <textarea name="reason" required rows="2" placeholder="Enter reason (required for audit log)…"
            class="w-full max-w-lg rounded-lg bg-white border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 resize-none"></textarea>
        <button type="submit" class="px-4 py-2 rounded-lg bg-red-800 hover:bg-red-700 text-white text-sm font-medium transition-colors">
            Confirm Termination
        </button>
    </form>
</div>
@endif

{{-- Consent Records --}}
@if($consents->count())
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="font-semibold text-gray-800">Consent Records</h3>
    </div>
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 bg-gray-50/50">
                <th class="text-left px-4 py-2 text-xs text-gray-500">Document</th>
                <th class="text-left px-4 py-2 text-xs text-gray-500">Version</th>
                <th class="text-left px-4 py-2 text-xs text-gray-500">Accepted At</th>
                <th class="text-left px-4 py-2 text-xs text-gray-500">IP</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-800">
            @foreach($consents as $c)
            <tr>
                <td class="px-4 py-2 font-medium text-gray-700">{{ strtoupper($c->document_type) }}</td>
                <td class="px-4 py-2 font-mono text-gray-600 text-xs">{{ $c->document_version }}</td>
                <td class="px-4 py-2 text-gray-600 text-xs">{{ $c->accepted_at }}</td>
                <td class="px-4 py-2 text-gray-500 text-xs">{{ $c->ip }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Audit Trail --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="font-semibold text-gray-800">Audit Trail</h3>
    </div>
    <div class="divide-y divide-gray-800">
        @forelse($auditLogs as $log)
        <div class="px-6 py-3">
            <div class="flex items-start justify-between">
                <span class="text-xs font-mono text-gray-700">{{ $log->action }}</span>
                <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y H:i:s') }}</span>
            </div>
            @if($log->actor_email)
            <p class="text-xs text-gray-500 mt-0.5">by {{ $log->actor_email }}</p>
            @endif
            @if($log->details)
            <details class="mt-1">
                <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">details</summary>
                <pre class="text-xs text-gray-500 mt-1 bg-white rounded p-2 overflow-x-auto">{{ json_encode(json_decode($log->details), JSON_PRETTY_PRINT) }}</pre>
            </details>
            @endif
        </div>
        @empty
        <p class="px-6 py-4 text-sm text-gray-500">No audit entries.</p>
        @endforelse
    </div>
</div>

@endsection
