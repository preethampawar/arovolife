@extends('admin.layouts.admin')
@section('title', 'Payment '.$intent->order->order_no)
@section('heading', 'Payment · '.$intent->order->order_no)

@section('content')

@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>@endif
@error('sync')<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-4">Intent</h3>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Order</dt><dd><a href="{{ route('admin.commerce.orders.show', $intent->order) }}" class="text-brand-700 font-mono">{{ $intent->order->order_no }}</a> · {{ $intent->order->status }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Amount</dt><dd class="font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($intent->amount_paise / 100, 2) }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Gateway</dt><dd>{{ ucfirst($intent->gateway) }} @if($intent->mode)<span class="text-xs text-gray-500">({{ $intent->mode }} mode)</span>@endif</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Status</dt><dd>{{ ucfirst($intent->status) }}@if($intent->cancel_reason) · {{ str_replace('_', ' ', $intent->cancel_reason) }}@endif</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Gateway order</dt><dd class="font-mono text-xs">{{ $intent->gateway_order_id ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Gateway payment</dt><dd class="font-mono text-xs">{{ $intent->gateway_payment_id ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Method</dt><dd>{{ $intent->method ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Attempts</dt><dd>{{ $intent->attempt_count }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Created</dt><dd>{{ $intent->created_at->format('d M Y H:i:s') }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Expires</dt><dd>{{ $intent->expires_at?->format('d M Y H:i:s') ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Captured</dt><dd>{{ $intent->captured_at?->format('d M Y H:i:s') ?? '—' }} @if($intent->confirmed_via)<span class="text-xs text-gray-500">via {{ $intent->confirmed_via }}</span>@endif</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Last synced</dt><dd>{{ $intent->last_synced_at?->format('d M Y H:i:s') ?? 'never' }}</dd></div>
                @if($intent->error_code)
                <div class="col-span-2"><dt class="text-xs text-gray-600 uppercase font-medium">Last gateway error</dt><dd class="text-red-700">{{ $intent->error_code }} — {{ $intent->error_description }}</dd></div>
                @endif
            </dl>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-1">Timeline</h3>
            <p class="text-xs text-gray-600 mb-4">Every call we made, every callback the browser posted, every webhook the gateway sent. Payloads are stored already scrubbed — no contact, email, VPA or cardholder name — and dropped after {{ \App\Modules\Payments\Console\Commands\PaymentsRedactEventsCommand::DEFAULT_DAYS }} days.</p>
            <ol class="space-y-3">
                @forelse($events as $event)
                <li class="border border-gray-200 rounded-lg p-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-mono text-gray-500">{{ $event->created_at->format('d M H:i:s') }}</span>
                        <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-700">{{ $event->direction }}</span>
                        <span class="font-medium text-gray-900">{{ $event->event_type }}</span>
                        @if($event->http_status)<span class="text-gray-500">HTTP {{ $event->http_status }}</span>@endif
                        @if($event->duration_ms !== null)<span class="text-gray-500">{{ $event->duration_ms }} ms</span>@endif
                        @if($event->direction !== 'outbound')<span class="{{ $event->signature_verified ? 'text-green-700' : 'text-gray-500' }}">{{ $event->signature_verified ? 'signature verified' : 'unsigned' }}</span>@endif
                        @if($event->gateway_event_id)<span class="font-mono text-gray-500">{{ $event->gateway_event_id }}</span>@endif
                        @if($event->processed_at)<span class="text-green-700">applied {{ $event->processed_at->format('H:i:s') }}</span>@endif
                    </div>
                    @if($event->error)<p class="text-xs text-red-700 mt-1">{{ $event->error }}</p>@endif
                    @if($event->processing_error)<p class="text-xs text-red-700 mt-1">{{ $event->processing_error }}</p>@endif
                    @if($event->payload)
                    <details class="mt-2"><summary class="text-xs text-brand-700 cursor-pointer">Payload</summary>
                        <pre class="mt-1 text-xs bg-gray-50 rounded p-2 overflow-x-auto">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                    @endif
                </li>
                @empty
                <li class="text-sm text-gray-600">No events recorded.</li>
                @endforelse
            </ol>
        </div>
    </div>

    <div class="space-y-6">
        @can('finance.record')
        @if($intent->gateway === 'razorpay' && $intent->status !== 'captured' && $intent->gateway_order_id)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-2">Sync with gateway</h3>
            <p class="text-xs text-gray-600 mb-3">Asks Razorpay what happened to this order and applies its answer through the normal confirmation checks. If it reports a capture for the exact amount, the order becomes paid and BV accrues — this is logged against your user.</p>
            <form method="POST" action="{{ route('admin.payments.sync', $intent) }}"
                  data-confirm="Ask the gateway and apply its answer?"
                  data-confirm-title="Sync payment"
                  data-confirm-impact="Impact: if Razorpay reports the payment captured, the order is marked paid, BV accrues and the compensation engines run. Nothing is marked paid on your word — only on the gateway's.">
                @csrf
                <button type="submit" class="w-full py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">Sync now</button>
            </form>
        </div>
        @endif
        @endcan

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-3">Refunds on this order</h3>
            @forelse($refunds as $refund)
            @php $c = $classify($refund); @endphp
            <div class="border-t border-gray-100 pt-3 mt-3 first:border-0 first:pt-0 first:mt-0 text-sm">
                <div class="flex justify-between"><span class="font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($refund->amount_paise / 100, 2) }}</span><span class="text-xs {{ $c['overdue'] ? 'text-red-700' : 'text-gray-600' }}">{{ $c['label'] }}</span></div>
                <div class="text-xs text-gray-600 mt-1">{{ $refund->reason_code }} · {{ $refund->status }} @if($refund->gateway_refund_id)· <span class="font-mono">{{ $refund->gateway_refund_id }}</span>@endif @if($refund->settled_via)· via {{ $refund->settled_via }}@endif</div>
                @if($refund->error_code)<div class="text-xs text-red-700 mt-1">{{ $refund->error_code }} — {{ $refund->error_description }}</div>@endif
            </div>
            @empty
            <p class="text-sm text-gray-600">None.</p>
            @endforelse
            <a href="{{ route('admin.payments.refunds') }}" class="block mt-4 text-sm text-brand-700 hover:underline">Unsettled refunds worklist →</a>
        </div>
    </div>
</div>

@endsection
