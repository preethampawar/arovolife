@php
    use App\Modules\Identity\Models\User;
    use App\Modules\Shared\Support\IndianNumber;
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions><x-ui.panel-actions :generated-at="$generated_at" :title="$panelTitle" /></x-slot:actions>

    <x-ui.stat-row :columns="6">
        <x-ui.stat flush :label-lines="2" :label="User::STATUS_LABELS['active']"
                   :value="IndianNumber::format($active)"
                   :href="route('admin.distributors.index', ['status' => 'active'])" />
        <x-ui.stat flush :label-lines="2" :label="User::STATUS_LABELS['pending']"
                   :value="IndianNumber::format($pending)"
                   :href="route('admin.distributors.index', ['status' => 'pending'])" />
        <x-ui.stat flush :label-lines="2" :label="User::STATUS_LABELS['frozen']"
                   :value="IndianNumber::format($frozen)"
                   :href="route('admin.distributors.index', ['status' => 'frozen'])" />
        <x-ui.stat flush :label-lines="2" label="Joined this month"
                   :value="IndianNumber::format($joined_this_month)"
                   :href="route('admin.distributors.index')" />
        {{-- `status=active` as well as the cooling-off filter: the counts
             restrict to active distributors and the list filter on its own
             does not, so without it the cell and the list it opens report
             two different numbers for a statutory window (hard rule 5). --}}
        <x-ui.stat flush :label-lines="2" label="Cooling-off active"
                   :value="IndianNumber::format($cooling_off_active)"
                   :href="route('admin.distributors.index', ['cooling_off' => 'active', 'status' => 'active'])" />
        <x-ui.stat flush :label-lines="2" label="Cooling-off ending ≤7d"
                   :value="IndianNumber::format($cooling_off_expiring)"
                   :href="route('admin.distributors.index', ['cooling_off' => 'expiring', 'status' => 'active'])" />
    </x-ui.stat-row>
</x-ui.card>
