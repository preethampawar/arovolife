@php
    use App\Modules\Compensation\Models\PayoutBatch;
    use App\Modules\Compensation\Models\PayoutLineItem;
    use App\Modules\Shared\Support\IndianNumber;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $payoutsUrl = route('admin.compensation.weekly-payouts.index');
    $engineRunsUrl = route('admin.compensation.engine-runs.index');

    $rupees = fn (int $paise): string => IndianNumber::rupees($paise);

    $latest = $money['latest_batch'];
    $report = $engines['report'];

    // PayoutLineItem carries no label map of its own — these four held
    // statuses are the only ones this panel ever renders, so the mapping
    // lives here rather than in the model.
    $heldLabels = [
        PayoutLineItem::STATUS_WEB_ONLY => 'Web-only',
        PayoutLineItem::STATUS_KYC_PENDING => 'KYC pending',
        PayoutLineItem::STATUS_NO_BANK_ACCOUNT => 'No bank account',
        PayoutLineItem::STATUS_BANK_DECRYPT_FAILED => 'Bank unreadable',
    ];

    // Likewise for the health report's five buckets. Order matches the DTO's
    // own constructor order.
    $buckets = [
        ['label' => 'Failed runs', 'count' => count($report->failures)],
        ['label' => 'Missing runs', 'count' => count($report->missing)],
        ['label' => 'Stuck runs', 'count' => count($report->stuck)],
        ['label' => 'Premature freezes', 'count' => count($report->prematureFreezes)],
        ['label' => 'Chain alerts', 'count' => count($report->chainAlerts)],
    ];

    $alertTotal = array_sum(array_column($buckets, 'count'));
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions><x-ui.panel-actions :generated-at="$generated_at" :title="$panelTitle" /></x-slot:actions>

    <x-ui.stat-row :columns="4">
        <x-ui.stat flush :label-lines="2" label="Latest batch" :href="$payoutsUrl"
                   :value="$latest === null ? '—' : $rupees($latest['total_net_paise'])"
                   :hint="$latest === null
                        ? 'No payout batches yet'
                        : Str::headline($latest['batch_type']).' · '.Carbon::parse($latest['batch_date'])->format('d M Y')">
            @if($latest !== null)
                <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                    <x-ui.badge :tone="match ($latest['status']) {
                        PayoutBatch::STATUS_COMPLETED => 'success',
                        PayoutBatch::STATUS_FAILED, PayoutBatch::STATUS_PARTIALLY_FAILED => 'danger',
                        PayoutBatch::STATUS_PROCESSING => 'warning',
                        default => 'neutral',
                    }">{{ Str::headline($latest['status']) }}</x-ui.badge>
                    {{-- Liveness, not `updated_at`: a sweep writes that twice and
                         a stalled batch would otherwise look busy. --}}
                    @if($stuck_since !== null)
                        <x-ui.badge tone="danger" dot>Idle since {{ $stuck_since->diffForHumans() }}</x-ui.badge>
                    @endif
                </div>
            @endif
        </x-ui.stat>

        <x-ui.stat flush :label-lines="2" label="Awaiting approval" :href="$payoutsUrl"
                   :value="IndianNumber::format($money['awaiting_approval'])"
                   hint="Batches pending a checker" />

        <x-ui.stat flush :label-lines="2" label="Held" :href="$payoutsUrl"
                   :value="$rupees($money['held_total_paise'])"
                   hint="Accrued, nothing can pay it out">
            @if(count($money['held']) > 0)
                <dl class="mt-3 space-y-1 text-xs">
                    @foreach($money['held'] as $status => $amountPaise)
                        <div class="flex items-center justify-between gap-2 text-gray-500">
                            <dt>{{ $heldLabels[$status] ?? Str::headline($status) }}</dt>
                            <dd class="tabular-nums text-gray-700">{{ $rupees($amountPaise) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.stat>

        <x-ui.stat flush :label-lines="2" label="Engine health" :href="$engineRunsUrl"
                   :value="$report->isHealthy() ? 'Healthy' : IndianNumber::format($alertTotal)"
                   :hint="$report->isHealthy() ? 'Every engine ran' : 'Runs needing attention'">
            @unless($report->isHealthy())
                <dl class="mt-3 space-y-1 text-xs">
                    @foreach($buckets as $bucket)
                        @continue($bucket['count'] === 0)
                        <div class="flex items-center justify-between gap-2 text-red-600">
                            <dt>{{ $bucket['label'] }}</dt>
                            <dd class="tabular-nums">{{ IndianNumber::format($bucket['count']) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endunless
        </x-ui.stat>
    </x-ui.stat-row>

    {{-- What the plan cost this month, one cell per bonus. The repurchase-pot
         types are deliberately absent from the source list and must stay
         absent: adding them back counts the same rupee twice. --}}
    @if(count($money['commission']) > 0)
        <x-ui.stat-row :columns="4" heading="Bonus credits this month">
            @foreach($money['commission'] as $type => $amountPaise)
                <x-ui.stat flush :label-lines="2"
                           :label="$money['commission_labels'][$type] ?? Str::headline($type)"
                           :value="$rupees($amountPaise)" />
            @endforeach
        </x-ui.stat-row>
    @endif
</x-ui.card>
