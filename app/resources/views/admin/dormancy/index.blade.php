@extends('admin.layouts.admin')
@section('title', 'Dormancy (§21)')
@section('heading', 'Dormancy & termination')

@section('content')
@php
    $filters = [
        'under_notice' => 'Under notice',
        'dormant' => 'Dormant, no notice yet',
        'terminated' => 'Terminated for dormancy',
    ];
@endphp

<div class="mb-6 max-w-3xl text-sm leading-relaxed text-slate-400">
    <p>
        Agreement §21 closes an account after <strong>{{ $inactivityMonths }} continuous months</strong>
        without a sale, following <strong>{{ $noticeDays }} days</strong> of written notice. The clock runs
        from the effective date or the last sale, whichever is later. One sale inside the notice window
        withdraws the notice entirely — nothing is lost and no residue is kept.
    </p>
</div>

@if (! $sweepEnabled)
    <div class="mb-6 rounded-xl border border-amber-600/40 bg-amber-500/10 px-5 py-4 text-sm text-amber-200">
        <p class="font-semibold">Automatic termination is OFF.</p>
        <p class="mt-1 leading-relaxed">
            The nightly sweep reports what it would do and writes nothing — no notices are sent and no
            account is closed. This list is what it would act on. Review it, confirm the sales attribution
            looks right, then turn the switch on in
            <a href="{{ route('admin.settings') }}" class="underline">Settings → Termination</a>.
            There is no path back from a terminated account.
        </p>
    </div>
@endif

@if (session('status'))
    <div class="mb-6 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-rose-600/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
        {{ $errors->first() }}
    </div>
@endif

<div class="mb-5 flex flex-wrap gap-2">
    @foreach ($filters as $value => $label)
        <a href="{{ route('admin.dormancy.index', ['filter' => $value]) }}"
           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition
                  {{ $filter === $value ? 'bg-sunrise-500 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

@if ($distributors->isEmpty())
    <div class="rounded-xl border border-dashed border-slate-700 px-6 py-12 text-center text-slate-400">
        Nobody in this state.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-700">
        <table class="min-w-full divide-y divide-slate-700 text-sm">
            <thead class="bg-slate-800 text-left text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-4 py-3">ADN</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Last sale</th>
                    <th class="px-4 py-3">Clock from</th>
                    @if ($filter === 'terminated')
                        <th class="px-4 py-3">Terminated</th>
                        <th class="px-4 py-3">May rejoin</th>
                    @else
                        <th class="px-4 py-3">Dormant since</th>
                        <th class="px-4 py-3">Notice</th>
                        <th class="px-4 py-3"></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                @foreach ($distributors as $distributor)
                    @php $assessment = $assessments[$distributor->id]; @endphp
                    <tr class="hover:bg-slate-800/60">
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.distributors.show', $distributor->id) }}"
                               class="font-semibold text-sunrise-400 underline">{{ $distributor->adn }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-200">{{ $distributor->user?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-400">
                            {{ $assessment->lastSaleAt?->format('d M Y') ?? 'Never sold' }}
                        </td>
                        <td class="px-4 py-3 text-slate-400">{{ $assessment->clockRunningFrom->format('d M Y') }}</td>

                        @if ($filter === 'terminated')
                            <td class="px-4 py-3 text-slate-300">{{ $distributor->terminated_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-300">
                                {{ $distributor->reregistration_allowed_from?->format('d M Y') ?? 'No wait' }}
                            </td>
                        @else
                            <td class="px-4 py-3 {{ $assessment->isDormant ? 'text-rose-300' : 'text-slate-400' }}">
                                {{ $assessment->dormantFrom->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-slate-300">
                                @if ($assessment->noticeExpiresAt)
                                    Closes {{ $assessment->noticeExpiresAt->format('d M Y') }}
                                    <span class="block text-xs text-slate-500">
                                        {{ $assessment->daysLeftOnNotice() }} day(s) left
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($assessment->isUnderNotice())
                                    <form method="POST" action="{{ route('admin.dormancy.withdraw', $distributor->id) }}"
                                          class="flex flex-wrap items-center justify-end gap-2"
                                          data-confirm="Withdraw the dormancy notice for {{ $distributor->adn }}?"
                                          data-confirm-title="Withdraw a §21 notice"
                                          data-confirm-impact="The account will not be closed by the sweep. It becomes liable again only if it stays dormant.">
                                        @csrf
                                        <input type="text" name="reason" maxlength="500" required
                                               placeholder="Reason"
                                               class="w-40 rounded-lg border-slate-600 bg-slate-800 text-xs text-slate-100 placeholder-slate-500">
                                        <button type="submit"
                                                class="rounded-lg border border-slate-600 px-3 py-1.5 text-xs text-slate-200 hover:bg-slate-800">
                                            Withdraw
                                        </button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $distributors->links() }}</div>
@endif

<x-confirm-modal />
@endsection
