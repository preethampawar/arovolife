<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Admin actions on this distributor's compensation records (manual credits, reversals, freezes). From the system audit log.
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($auditRows) || (method_exists($auditRows, 'isEmpty') && $auditRows->isEmpty()))
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No compensation audit entries yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">When</th>
                <th class="px-3 py-2 text-left text-gray-500">Action</th>
                <th class="px-3 py-2 text-left text-gray-500">By</th>
                <th class="px-3 py-2 text-left text-gray-500">Details</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($auditRows as $row)
            <tr>
                <td class="px-3 py-2 text-gray-500">{{ $row->created_at?->diffForHumans() }}</td>
                <td class="px-3 py-2 font-mono">{{ $row->action }}</td>
                <td class="px-3 py-2">{{ $row->actor?->full_name ?? $row->actor_id ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-500">{{ is_array($row->details) ? json_encode($row->details) : ($row->details ?? '—') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $auditRows->links() }}</div>
    @endif
</div>
