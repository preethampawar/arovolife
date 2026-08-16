@extends('admin.layouts.admin')
@section('title', 'Franchise '.$franchise->code)
@section('heading', 'Franchise '.$franchise->code)

@section('content')
<a href="{{ route('admin.commerce.franchises.index') }}" class="text-sm text-sunrise-400 underline">← Franchises</a>

@if (session('status'))
    <div class="mt-4 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mt-4 rounded-lg border border-rose-600/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="POST" action="{{ route('admin.commerce.franchises.update', $franchise->id) }}"
              class="space-y-5 rounded-xl border border-slate-700 bg-slate-900/40 p-6"
              data-confirm="Save these changes?"
              data-confirm-title="Update franchise {{ $franchise->code }}"
              data-confirm-impact="A rate change applies from the next monthly run. Months already credited keep the rate they were paid at.">
            @csrf
            @method('PATCH')

            @include('admin.commerce.franchises._form', ['franchise' => $franchise, 'planRateBp' => $planRateBp])

            <button type="submit" class="rounded-lg bg-sunrise-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-sunrise-600">
                Save changes
            </button>
        </form>

        <div class="mt-6 rounded-xl border border-slate-700 bg-slate-900/40 p-6">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-400">
                Commission history
            </h2>

            @if ($results->isEmpty())
                <p class="text-sm text-slate-500">No commission has been run for this franchise yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-700 text-sm">
                        <thead class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="py-2 pr-4">Month</th>
                                <th class="py-2 pr-4">Orders</th>
                                <th class="py-2 pr-4">Fulfilled value</th>
                                <th class="py-2 pr-4">Rate</th>
                                <th class="py-2 pr-4">Commission</th>
                                <th class="py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($results as $result)
                                <tr>
                                    <td class="py-2 pr-4 text-slate-200">{{ $result->month_start->format('M Y') }}</td>
                                    <td class="py-2 pr-4 text-slate-400">{{ $result->order_count }}</td>
                                    <td class="py-2 pr-4 text-slate-400">₹@bv($result->base_paise / 100)</td>
                                    <td class="py-2 pr-4 text-slate-400">{{ number_format($result->rate_bp / 100, 2) }}%</td>
                                    <td class="py-2 pr-4 font-semibold text-slate-100">₹@bv($result->gross_paise / 100)</td>
                                    <td class="py-2 text-slate-400">{{ ucfirst($result->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-5 text-sm">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">Register</h2>
            <dl class="space-y-2.5">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Code</dt>
                    <dd class="font-mono text-slate-200">{{ $franchise->code }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Status</dt>
                    <dd class="text-slate-200">{{ ucfirst(str_replace('_', ' ', $franchise->status)) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Applied</dt>
                    <dd class="text-slate-200">{{ $franchise->applied_at?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Approved</dt>
                    <dd class="text-slate-200">{{ $franchise->approved_at?->format('d M Y') ?? '—' }}</dd>
                </div>
                @if ($franchise->approvedBy)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Approved by</dt>
                        <dd class="text-right text-slate-200">{{ $franchise->approvedBy->full_name }}</dd>
                    </div>
                @endif
                @if ($franchise->areteCenter)
                    <div class="flex justify-between gap-3 border-t border-slate-800 pt-2.5">
                        <dt class="text-slate-500">Arete centre</dt>
                        <dd class="text-right text-slate-200">{{ $franchise->areteCenter->name }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="space-y-4 rounded-xl border border-slate-700 bg-slate-900/40 p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Lifecycle</h2>

            @if ($franchise->status === \App\Modules\Commerce\Models\Franchise::STATUS_PENDING)
                <form method="POST" action="{{ route('admin.commerce.franchises.approve', $franchise->id) }}"
                      data-confirm="Approve {{ $franchise->code }}?"
                      data-confirm-title="Approve this franchise"
                      data-confirm-impact="It becomes selectable as a collection point at checkout and starts earning the monthly commission.">
                    @csrf
                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                        Approve
                    </button>
                </form>
            @endif

            @if ($franchise->status === \App\Modules\Commerce\Models\Franchise::STATUS_ACTIVE)
                <form method="POST" action="{{ route('admin.commerce.franchises.status', [$franchise->id, 'suspend']) }}"
                      class="space-y-2"
                      data-confirm="Suspend {{ $franchise->code }}?"
                      data-confirm-title="Suspend this franchise"
                      data-confirm-impact="It disappears from the checkout picker immediately. Orders already routed to it still need fulfilling.">
                    @csrf
                    <input type="text" name="reason" maxlength="500" required placeholder="Reason"
                           class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
                    <button type="submit" class="w-full rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                        Suspend
                    </button>
                </form>
            @endif

            @if ($franchise->status === \App\Modules\Commerce\Models\Franchise::STATUS_SUSPENDED)
                <form method="POST" action="{{ route('admin.commerce.franchises.status', [$franchise->id, 'reinstate']) }}"
                      class="space-y-2"
                      data-confirm="Reinstate {{ $franchise->code }}?"
                      data-confirm-title="Reinstate this franchise"
                      data-confirm-impact="It becomes selectable at checkout again and resumes earning.">
                    @csrf
                    <input type="text" name="reason" maxlength="500" required placeholder="Reason"
                           class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
                    <button type="submit" class="w-full rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800">
                        Reinstate
                    </button>
                </form>
            @endif

            @if ($franchise->status !== \App\Modules\Commerce\Models\Franchise::STATUS_CLOSED)
                <form method="POST" action="{{ route('admin.commerce.franchises.status', [$franchise->id, 'close']) }}"
                      class="space-y-2"
                      data-confirm="Close {{ $franchise->code }} permanently?"
                      data-confirm-title="Close this franchise"
                      data-confirm-impact="It stops earning and cannot be chosen at checkout. Commission already credited is unaffected.">
                    @csrf
                    <input type="text" name="reason" maxlength="500" required placeholder="Reason"
                           class="w-full rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
                    <button type="submit" class="w-full rounded-lg border border-rose-700 px-4 py-2 text-sm text-rose-300 hover:bg-rose-900/30">
                        Close
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>

<x-confirm-modal />
@endsection
