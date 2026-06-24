@extends('admin.layouts.admin')
@section('title', 'Cut-off '.$parsed->format('d M Y'))
@section('heading', 'GSB Cut-off — '.$parsed->format('d M Y'))

@section('content')

<div class="mb-4">
    <a href="{{ route('admin.compensation.daily-cutoffs.index', ['date' => $parsed->toDateString()]) }}"
       class="text-sm text-brand-600 hover:underline">← Back to cut-offs list</a>
</div>

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    All distributors processed in the {{ $parsed->format('d M Y') }} 23:59 cut-off. Use
    <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Manual Controls</a>
    to retry failed rows or reverse incorrect credits.
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">No cut-off data for this date.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500">Name</th>
                    <th class="px-3 py-2 text-right text-gray-500">Left BV</th>
                    <th class="px-3 py-2 text-right text-gray-500">Right BV</th>
                    <th class="px-3 py-2 text-center text-gray-500">Slab</th>
                    <th class="px-3 py-2 text-right text-gray-500">Gross GSB</th>
                    <th class="px-3 py-2 text-right text-gray-500">Admin 3%</th>
                    <th class="px-3 py-2 text-right text-gray-500">TDS 5%</th>
                    <th class="px-3 py-2 text-right text-gray-500">Net GSB</th>
                    <th class="px-3 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                    $badges = [
                        'credited'    => 'bg-green-100 text-green-700',
                        'failed'      => 'bg-red-100 text-red-700',
                        'no_match'    => 'bg-gray-100 text-gray-500',
                        'frozen'      => 'bg-blue-100 text-blue-700',
                        'below_600bv' => 'bg-amber-100 text-amber-700',
                    ];
                @endphp
                <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[120px]">
                        {{ $row->distributor->user?->full_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                    <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                    <td class="px-3 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">
                        {{ $row->gross_gsb_paise ? '₹'.number_format($row->gross_gsb_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-500">
                        {{ $row->admin_charge_paise ? '₹'.number_format($row->admin_charge_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-500">
                        {{ $row->tds_paise ? '₹'.number_format($row->tds_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badges[$row->status] ?? 'bg-gray-100 text-gray-500' }}">
                            {{ str_replace('_', ' ', $row->status) }}
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
