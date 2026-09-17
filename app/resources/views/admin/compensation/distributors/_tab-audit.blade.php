@developer
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Admin actions on this distributor's compensation records (cut-off retries, reversals, freezes). From the system audit log. Rows dated before 17 September 2026 may carry <code>carryforward.recalculated</code>, <code>payout.force_triggered</code> or <code>gsb.manual_credit</code>: the first two recorded a request that performed nothing and the controls were removed, and manual credits are no longer written at all.
</div>
@enddeveloper
<x-ui.card flush>
    @if(empty($auditRows) || (method_exists($auditRows, 'isEmpty') && $auditRows->isEmpty()))
    <x-ui.empty-state title="No compensation audit entries yet." />
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600 w-12">S.No.</th>
                <th class="px-3 py-2 text-left text-gray-600">When</th>
                <th class="px-3 py-2 text-left text-gray-600">Action</th>
                <th class="px-3 py-2 text-left text-gray-600">By</th>
                <th class="px-3 py-2 text-left text-gray-600">Details</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($auditRows as $row)
            <tr>
                <td class="px-3 py-2 text-gray-500 tabular-nums">{{ $auditRows->firstItem() + $loop->index }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $row->created_at?->diffForHumans() }}</td>
                <td class="px-3 py-2 font-mono">{{ $row->action }}</td>
                <td class="px-3 py-2">{{ $row->actor?->full_name ?? $row->actor_id ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ is_array($row->details) ? json_encode($row->details) : ($row->details ?? '—') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $auditRows->links() }}</div>
    @endif
</x-ui.card>
