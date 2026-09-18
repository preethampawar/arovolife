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
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-ui.stat :label-lines="2" :label="\App\Modules\Identity\Models\User::STATUS_LABELS['active']"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($active)"
                       :href="route('admin.distributors.index', ['status' => 'active'])" />
            <x-ui.stat :label-lines="2" :label="\App\Modules\Identity\Models\User::STATUS_LABELS['pending']"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($pending)"
                       :href="route('admin.distributors.index', ['status' => 'pending'])" />
            <x-ui.stat :label-lines="2" :label="\App\Modules\Identity\Models\User::STATUS_LABELS['frozen']"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($frozen)"
                       :href="route('admin.distributors.index', ['status' => 'frozen'])" />
            <x-ui.stat :label-lines="2" label="Joined this month"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($joined_this_month)"
                       :href="route('admin.distributors.index')" />
            {{-- `status=active` as well as the cooling-off filter: the counts
                 restrict to active distributors and the list filter on its own
                 does not, so without it the tile and the list it opens report
                 two different numbers for a statutory window (hard rule 5). --}}
            <x-ui.stat :label-lines="2" label="Cooling-off active"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($cooling_off_active)"
                       :href="route('admin.distributors.index', ['cooling_off' => 'active', 'status' => 'active'])" />
            <x-ui.stat :label-lines="2" label="Cooling-off ending ≤7d"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($cooling_off_expiring)"
                       :href="route('admin.distributors.index', ['cooling_off' => 'expiring', 'status' => 'active'])" />
        </div>

        <div>
            <h4 class="text-xs font-semibold text-gray-600">Recent registrations</h4>
            @if($latest === [])
                <x-ui.empty-state title="No registrations yet." icon="users" class="mt-1.5 rounded-lg border border-gray-200" />
            @else
                <ul class="mt-1.5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    @foreach($latest as $row)
                        <li>
                            <a href="{{ route('admin.distributors.show', $row['id']) }}"
                               class="flex items-center justify-between gap-3 px-3 py-2.5 transition-colors hover:bg-gray-50">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-700">{{ $row['adn'] }}</p>
                                    @if($row['full_name'])
                                        <p class="truncate text-xs text-gray-500">{{ $row['full_name'] }}</p>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right">
                                    <x-ui.badge>{{ \App\Modules\Identity\Models\User::STATUS_LABELS[$row['status']] ?? \Illuminate\Support\Str::headline($row['status']) }}</x-ui.badge>
                                    <p class="mt-1 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($row['effective_date'])->format('d M Y, h:i A') }}</p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-ui.card>
