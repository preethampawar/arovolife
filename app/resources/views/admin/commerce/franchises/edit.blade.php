@extends('admin.layouts.admin')
@section('title', 'Franchise '.$franchise->code)
@section('heading', 'Franchise '.$franchise->code)

@section('content')
<a href="{{ route('admin.commerce.franchises.index') }}" class="text-sm text-brand-700 underline hover:text-brand-800">← Franchises</a>

@if (session('status'))
    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="POST" action="{{ route('admin.commerce.franchises.update', $franchise->id) }}"
              class="space-y-5 rounded-xl border border-gray-200 bg-white shadow-sm p-6"
              data-confirm="Save these changes?"
              data-confirm-title="Update franchise {{ $franchise->code }}"
              data-confirm-impact="A rate change applies from the next monthly run. Months already credited keep the rate they were paid at.">
            @csrf
            @method('PATCH')

            @include('admin.commerce.franchises._form', ['franchise' => $franchise, 'planRateBp' => $planRateBp])

            <button type="submit" class="rounded-lg bg-brand-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
                Save changes
            </button>
        </form>

        <div class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm p-6">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-600">
                Commission history
            </h2>

            @if ($results->isEmpty())
                <p class="text-sm text-gray-500">No commission has been run for this franchise yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="text-left text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="py-2 pr-4">Month</th>
                                <th class="py-2 pr-4">Orders</th>
                                <th class="py-2 pr-4">Fulfilled value</th>
                                <th class="py-2 pr-4">Rate</th>
                                <th class="py-2 pr-4">Commission</th>
                                <th class="py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($results as $result)
                                <tr>
                                    <td class="py-2 pr-4 text-gray-800">{{ $result->month_start->format('M Y') }}</td>
                                    <td class="py-2 pr-4 text-gray-600">{{ $result->order_count }}</td>
                                    <td class="py-2 pr-4 text-gray-600">₹@bv($result->base_paise / 100)</td>
                                    <td class="py-2 pr-4 text-gray-600">{{ number_format($result->rate_bp / 100, 2) }}%</td>
                                    <td class="py-2 pr-4 font-semibold text-gray-900">₹@bv($result->gross_paise / 100)</td>
                                    <td class="py-2 text-gray-600">{{ ucfirst($result->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 text-sm">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-600">Register</h2>
            <dl class="space-y-2.5">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Code</dt>
                    <dd class="font-mono text-gray-800">{{ $franchise->code }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Status</dt>
                    <dd class="text-gray-800">{{ ucfirst(str_replace('_', ' ', $franchise->status)) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Applied</dt>
                    <dd class="text-gray-800">{{ $franchise->applied_at?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Approved</dt>
                    <dd class="text-gray-800">{{ $franchise->approved_at?->format('d M Y') ?? '—' }}</dd>
                </div>
                @if ($franchise->approvedBy)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500">Approved by</dt>
                        <dd class="text-right text-gray-800">{{ $franchise->approvedBy->full_name }}</dd>
                    </div>
                @endif
                @if ($franchise->areteCenter)
                    <div class="flex justify-between gap-3 border-t border-gray-200 pt-2.5">
                        <dt class="text-gray-500">Arete centre</dt>
                        <dd class="text-right text-gray-800">{{ $franchise->areteCenter->name }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="space-y-4 rounded-xl border border-gray-200 bg-white shadow-sm p-5">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-600">Lifecycle</h2>

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
                           class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900 placeholder-gray-400">
                    <button type="submit" class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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
                           class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900 placeholder-gray-400">
                    <button type="submit" class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
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
                           class="w-full rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900 placeholder-gray-400">
                    <button type="submit" class="w-full rounded-lg border border-red-300 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-50">
                        Close
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>

<x-confirm-modal />
@endsection
