@php
    $payoutsUrl = route('admin.compensation.weekly-payouts.index');

    $batchStatusTone = $latest_batch === null ? null : match ($latest_batch['status']) {
        \App\Modules\Compensation\Models\PayoutBatch::STATUS_COMPLETED => 'success',
        \App\Modules\Compensation\Models\PayoutBatch::STATUS_FAILED,
        \App\Modules\Compensation\Models\PayoutBatch::STATUS_PARTIALLY_FAILED => 'danger',
        \App\Modules\Compensation\Models\PayoutBatch::STATUS_PROCESSING => 'warning',
        default => 'neutral',
    };

    // PayoutLineItem carries no label map of its own — these four held
    // statuses are the only ones this panel ever renders, so the mapping
    // lives here rather than in the model.
    $heldLabels = [
        \App\Modules\Compensation\Models\PayoutLineItem::STATUS_WEB_ONLY => 'Web-only (release pending)',
        \App\Modules\Compensation\Models\PayoutLineItem::STATUS_KYC_PENDING => 'KYC pending',
        \App\Modules\Compensation\Models\PayoutLineItem::STATUS_NO_BANK_ACCOUNT => 'No bank account on file',
        \App\Modules\Compensation\Models\PayoutLineItem::STATUS_BANK_DECRYPT_FAILED => 'Bank details unreadable',
    ];
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions>
        <span class="text-[11px] tabular-nums text-gray-500">as of {{ $generated_at->format('H:i') }}</span>
        <button type="button" data-panel-refresh
                class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                aria-label="Refresh {{ $panelTitle }}">
            {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
        </button>
    </x-slot:actions>

    <div class="p-5 space-y-5">
        @if($latest_batch === null)
            <x-ui.empty-state title="No payout batches yet." description="Weekly payout not yet active." />
        @else
            <a href="{{ $payoutsUrl }}" class="block rounded-xl border border-gray-200 p-4 transition-colors hover:bg-gray-50">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-900">
                            {{ \Illuminate\Support\Str::headline($latest_batch['batch_type']) }} batch — {{ \Illuminate\Support\Carbon::parse($latest_batch['batch_date'])->format('d M Y') }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ \App\Modules\Shared\Support\IndianNumber::format($latest_batch['distributor_count']) }} distributors
                        </p>
                    </div>
                    <x-ui.badge :tone="$batchStatusTone">{{ \Illuminate\Support\Str::headline($latest_batch['status']) }}</x-ui.badge>
                </div>
                <p class="mt-2.5 text-[22px] font-semibold leading-none tracking-tight tabular-nums text-gray-900">
                    {{ \App\Modules\Shared\Support\IndianNumber::rupees($latest_batch['total_net_paise']) }}
                </p>
                @if($stuck_since !== null)
                    <div class="mt-2.5">
                        <x-ui.badge tone="danger" dot>No activity since {{ $stuck_since->diffForHumans() }}</x-ui.badge>
                    </div>
                @endif
            </a>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <x-ui.stat :label-lines="2" label="Awaiting approval" :value="\App\Modules\Shared\Support\IndianNumber::format($awaiting_approval)" :href="$payoutsUrl" />
            <x-ui.stat :label-lines="2" label="Held" :value="\App\Modules\Shared\Support\IndianNumber::rupees($held_total_paise)" :href="$payoutsUrl" />
        </div>

        @if(count($held) > 0)
            <div>
                <h4 class="text-xs font-semibold text-gray-600">Held money</h4>
                <ul class="mt-1.5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    @foreach($held as $status => $amountPaise)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="text-gray-700">{{ $heldLabels[$status] ?? \Illuminate\Support\Str::headline($status) }}</span>
                            <span class="tabular-nums text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::rupees($amountPaise) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(count($commission) > 0)
            <div>
                <h4 class="text-xs font-semibold text-gray-600">Bonus credits this month</h4>
                <ul class="mt-1.5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    @foreach($commission as $type => $amountPaise)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="text-gray-700">{{ $commission_labels[$type] ?? \Illuminate\Support\Str::headline($type) }}</span>
                            <span class="tabular-nums text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::rupees($amountPaise) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-ui.card>
