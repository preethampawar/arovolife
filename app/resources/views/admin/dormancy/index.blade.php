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

<div class="mb-6 max-w-3xl text-sm leading-relaxed text-gray-700">
    <p>
        Agreement §21 closes an account after <strong>{{ $inactivityMonths }} continuous months</strong>
        without a sale, following <strong>{{ $noticeDays }} days</strong> of written notice. The clock runs
        from the effective date or the last sale, whichever is later. One sale inside the notice window
        withdraws the notice entirely — nothing is lost and no residue is kept.
    </p>
</div>

@if (! $sweepEnabled)
    <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900">
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
    <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mb-5 flex flex-wrap gap-2">
    @foreach ($filters as $value => $label)
        <a href="{{ route('admin.dormancy.index', ['filter' => $value]) }}"
           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition
                  {{ $filter === $value ? 'bg-slate-800 text-white' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-100' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

@if ($distributors->isEmpty())
    <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-gray-600">
        Nobody in this state.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
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
            <tbody class="divide-y divide-gray-100">
                @foreach ($distributors as $distributor)
                    @php $assessment = $assessments[$distributor->id]; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.distributors.show', $distributor->id) }}"
                               class="font-semibold text-brand-700 hover:text-brand-800 underline">{{ $distributor->adn }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-900">{{ $distributor->user?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ $assessment->lastSaleAt?->format('d M Y') ?? 'Never sold' }}
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $assessment->clockRunningFrom->format('d M Y') }}</td>

                        @if ($filter === 'terminated')
                            <td class="px-4 py-3 text-gray-800">{{ $distributor->terminated_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-800">
                                {{ $distributor->reregistration_allowed_from?->format('d M Y') ?? 'No wait' }}
                            </td>
                        @else
                            <td class="px-4 py-3 {{ $assessment->isDormant ? 'text-red-700' : 'text-gray-600' }}">
                                {{ $assessment->dormantFrom->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-gray-800">
                                @if ($assessment->noticeExpiresAt)
                                    Closes {{ $assessment->noticeExpiresAt->format('d M Y') }}
                                    <span class="block text-xs text-gray-500">
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
                                               class="w-40 rounded-lg border-gray-300 text-xs text-gray-900 placeholder-gray-400 focus:border-brand-500 focus:ring-brand-500">
                                        <button type="submit"
                                                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100">
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
