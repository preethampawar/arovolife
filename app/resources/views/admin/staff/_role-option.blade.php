{{--
    One role checkbox with a plain-language summary of what the role can and
    cannot do. Mirrors Database\Seeders\RolesAndPermissionsSeeder — keep the
    two in step when a permission moves between roles (R-17).

    @param string        $role
    @param array<string> $checked  role names to pre-tick
--}}
@php
    $roleGuide = [
        'admin' => [
            'title' => 'Administrator',
            'summary' => 'Full business access to the admin console.',
            'can' => [
                'Everything the three roles below can do',
                'Approve payout batches for payment (not batches they created)',
                'Approve GSB reversals (not reversals they raised)',
                'Change platform settings',
                'Reset a distributor’s password or correct their identity details',
            ],
            'cannot' => [],
        ],
        'admin-operations' => [
            'title' => 'Operations',
            'summary' => 'Runs the day-to-day network and order flow.',
            'can' => [
                'Review and decide KYC submissions',
                'Approve or reject line-change (placement) requests',
                'Ship, deliver and cancel orders, and mark returns received',
                'Manage inventory: warehouses, suppliers, purchase orders, stock transfers and adjustments',
                'Handle grievances, distributor requests, Arete Centre applications and reported messages',
                'Publish content pages and announcements',
                'View sales and profit reports, and the audit log',
            ],
            'cannot' => [
                'Record refunds or run payouts',
                'Freeze, unfreeze or terminate accounts',
            ],
        ],
        'admin-finance' => [
            'title' => 'Finance',
            'summary' => 'Handles money movements and financial reporting.',
            'can' => [
                'Record refunds and other finance entries',
                'Run payout batches, import bank responses and retry failed transfers',
                'View inventory stock and valuation (read-only)',
                'View sales and profit reports, and the audit log',
            ],
            'cannot' => [
                'Approve a payout batch for payment',
                'Freeze, unfreeze or terminate accounts',
                'Review KYC, or read grievances and distributor requests',
            ],
        ],
        'admin-compliance' => [
            'title' => 'Compliance',
            'summary' => 'Polices conduct and enforces the Direct Seller Agreement.',
            'can' => [
                'Freeze, unfreeze and terminate distributor accounts',
                'Raise (not approve) GSB reversal requests',
                'Handle grievances, distributor requests, Arete Centre applications and reported messages',
                'Publish content pages and announcements',
                'View the audit log',
            ],
            'cannot' => [
                'Record payments or refunds, or run payouts',
                'View sales, profit or inventory figures',
            ],
        ],
    ];
    $guide = $roleGuide[$role] ?? null;
@endphp
<label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4 cursor-pointer hover:border-brand-300 hover:bg-brand-50/40 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/60 transition-colors">
    <input type="checkbox" name="roles[]" value="{{ $role }}" data-field-label="Role: {{ $role }}" class="mt-1"
        @checked(in_array($role, $checked, true))>
    <span class="flex-1 min-w-0">
        <span class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-semibold text-sm text-gray-800">{{ $guide['title'] ?? $role }}</span>
            <span class="font-mono text-xs text-gray-500">{{ $role }}</span>
        </span>
        @if($guide)
            <span class="block text-xs text-gray-600 mt-0.5">{{ $guide['summary'] }}</span>
            <span class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 mt-3">
                <span class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-green-700 mb-1">Can</span>
                    @foreach($guide['can'] as $line)
                        <span class="flex items-start gap-1.5 text-xs text-gray-700 mb-0.5">
                            <x-lucide-check class="w-3.5 h-3.5 mt-0.5 shrink-0 text-green-600" />{{ $line }}
                        </span>
                    @endforeach
                </span>
                @if($guide['cannot'])
                    <span class="block">
                        <span class="block text-[11px] font-semibold uppercase tracking-wider text-red-700 mb-1">Cannot</span>
                        @foreach($guide['cannot'] as $line)
                            <span class="flex items-start gap-1.5 text-xs text-gray-700 mb-0.5">
                                <x-lucide-x class="w-3.5 h-3.5 mt-0.5 shrink-0 text-red-600" />{{ $line }}
                            </span>
                        @endforeach
                    </span>
                @endif
            </span>
        @endif
    </span>
</label>
