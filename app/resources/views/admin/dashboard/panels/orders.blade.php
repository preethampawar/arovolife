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
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach($pipeline as $status => $count)
                <x-ui.stat
                    :label-lines="2"
                    :label="\Illuminate\Support\Str::headline($status)"
                    :value="\App\Modules\Shared\Support\IndianNumber::format($count)"
                    :href="route('admin.commerce.orders.index', ['status' => $status])"
                />
            @endforeach
        </div>

        @if(count($exceptions) > 0)
            <div>
                <h4 class="text-xs font-semibold text-gray-600">Exceptions</h4>
                <ul class="mt-1.5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    @foreach($exceptions as $status => $count)
                        <li>
                            <a href="{{ route('admin.commerce.orders.index', ['status' => $status]) }}"
                               class="flex items-center justify-between gap-3 px-3 py-2 text-sm transition-colors hover:bg-gray-50">
                                <span class="text-gray-700">{{ \Illuminate\Support\Str::headline($status) }}</span>
                                <span class="tabular-nums text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($count) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-ui.card>
