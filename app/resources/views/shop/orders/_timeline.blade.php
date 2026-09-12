{{--
    Order timeline (inventory plan §7.4 / H13). Derived, never stored: every
    step reads a timestamp that already exists on the order, its cooling-off
    row or its return requests. Used by shop/orders/show and
    admin/commerce/orders-show. Dates only — no amounts, no BV.
--}}
@php
    $fmt = fn ($at) => $at?->format('d M Y, H:i');
    $coolingOff = $order->coolingOff;
    $latestReturn = in_array($order->status, ['refund_requested', 'refund_inspection', 'refund_approved', 'refunded'], true)
        ? $order->returnRequests()->latest('id')->first()
        : null;

    $steps = [
        ['label' => 'Placed', 'at' => $order->placed_at, 'note' => null],
        ['label' => 'Paid', 'at' => $order->paid_at, 'note' => null],
        ['label' => 'Packed', 'at' => $order->packed_at, 'note' => null],
        ['label' => 'Shipped', 'at' => $order->shipped_at, 'note' => ($order->ship_carrier || $order->ship_tracking_no)
            ? trim(($order->ship_carrier ?: 'Courier').($order->ship_tracking_no ? ' — '.$order->ship_tracking_no : ''))
            : null],
        ['label' => 'Delivered', 'at' => $order->delivered_at, 'note' => null],
    ];

    if ($order->cancelled_at !== null) {
        // A cancelled order never reached the later steps; show only what happened.
        $steps = array_values(array_filter($steps, fn ($s) => $s['at'] !== null));
        $steps[] = ['label' => 'Cancelled', 'at' => $order->cancelled_at, 'note' => null];
    } else {
        if ($coolingOff !== null) {
            $steps[] = [
                'label' => $coolingOff->ends_at->isFuture() ? 'Cooling-off period ends' : 'Cooling-off period ended',
                'at' => $coolingOff->ends_at->isFuture() ? null : $coolingOff->ends_at,
                'note' => $coolingOff->ends_at->isFuture() ? 'On '.$coolingOff->ends_at->format('d M Y') : null,
            ];
        } elseif ($order->delivered_at === null) {
            $steps[] = ['label' => 'Cooling-off period ends', 'at' => null, 'note' => '30 days after delivery'];
        }

        if ($latestReturn !== null) {
            $steps[] = ['label' => 'Return requested', 'at' => $latestReturn->created_at, 'note' => $latestReturn->rma_no];
            if ($order->status === 'refund_inspection') {
                $steps[] = ['label' => 'Return being inspected', 'at' => null, 'note' => null];
            }
            if ($order->refund_approved_at !== null) {
                $steps[] = ['label' => 'Refund approved', 'at' => $order->refund_approved_at, 'note' => null];
            }
            if ($order->refunded_at !== null) {
                $steps[] = ['label' => 'Refunded', 'at' => $order->refunded_at, 'note' => null];
            }
        }
    }
@endphp

<ol class="relative space-y-3" aria-label="Order timeline">
    @foreach($steps as $step)
    @php $done = $step['at'] !== null; @endphp
    <li class="flex items-start gap-3">
        <span class="mt-1 inline-block w-2.5 h-2.5 rounded-full shrink-0 {{ $done ? ($step['label'] === 'Cancelled' ? 'bg-red-500' : 'bg-green-600') : 'bg-gray-300' }}"></span>
        <div class="text-sm">
            <p class="{{ $done ? 'text-gray-900 font-medium' : 'text-gray-500' }}">{{ $step['label'] }}</p>
            @if($done)
            <p class="text-xs text-gray-600">{{ $fmt($step['at']) }}</p>
            @endif
            @if($step['note'])
            <p class="text-xs text-gray-600 font-mono break-all">{{ $step['note'] }}</p>
            @endif
        </div>
    </li>
    @endforeach
</ol>
