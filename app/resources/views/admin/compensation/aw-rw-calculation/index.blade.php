@extends('admin.layouts.admin')
@section('title', 'Awards & Rewards Report')
@section('heading', 'Awards & Rewards (AW & RW) Monthly Report')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global Lifetime Awards & Rewards table — one row per milestone delivery.
    <strong>Award</strong> = the physical goods item (e.g., iPhone, Royal Enfield).
    <strong>Reward</strong> = cash disbursement (where the distributor chose cash in lieu of goods).
    Filter by type to see goods-only or cash-only rows.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Search ADN or name…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-52">
    <input type="month" name="month" value="{{ $month ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="type" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All types</option>
        <option value="goods" {{ $type === 'goods' ? 'selected' : '' }}>Goods (Awards)</option>
        <option value="cash" {{ $type === 'cash' ? 'selected' : '' }}>Cash (Rewards)</option>
    </select>
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="delivered" {{ $status === 'delivered' ? 'selected' : '' }}>Delivered</option>
        <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($q || $month || $type || $status)
    <a href="{{ route('admin.compensation.aw-rw-calculation.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.aw-rw-calculation.export', array_filter(['q' => $q, 'month' => $month, 'type' => $type, 'status' => $status])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No award or reward records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Rank</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Month</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">
                        Award
                        <x-help-tip text="Physical goods awarded (e.g. iPhone, Royal Enfield). Shown when disbursement type is 'goods'." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">
                        Reward
                        <x-help-tip text="Cash disbursement in lieu of goods. Shown when disbursement type is 'cash'." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                    $isCash = $row->disbursement_type === 'cash';
                    $statusBadges = [
                        'delivered'  => 'bg-green-100 text-green-700',
                        'pending'    => 'bg-amber-100 text-amber-700',
                        'cancelled'  => 'bg-red-100 text-red-700',
                    ];
                @endphp
                <tr class="hover:bg-gray-50 {{ $isCash ? 'bg-emerald-50/30' : '' }}">
                    <td class="px-3 py-2 text-gray-400">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.lifetime-awards.index') }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->adn }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700">{{ $row->full_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <span class="font-medium text-purple-700">
                            {{ $row->rank_name ?? 'Rank '.$row->rank_number }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->triggered_month)->format('M Y') }}
                    </td>
                    <td class="px-3 py-2">
                        @if(!$isCash && $row->award_description)
                        <span class="font-medium text-gray-800">{{ $row->award_description }}</span>
                        @else
                        <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($isCash && $row->net_paise)
                        <span class="font-semibold text-green-700">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}
                        </span>
                        @if($row->gross_paise && $row->gross_paise !== $row->net_paise)
                        <span class="block text-[10px] text-gray-400 font-normal">
                            gross ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}
                        </span>
                        @endif
                        @else
                        <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ $row->status }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
