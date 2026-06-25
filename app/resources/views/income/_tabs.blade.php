@php
$tabs = [
    ['route' => 'income.dashboard',   'label' => 'Dashboard'],
    ['route' => 'income.genos-bv',    'label' => 'Genos BV'],
    ['route' => 'income.gsb-history', 'label' => 'GSB History'],
    ['route' => 'income.mentorship',  'label' => 'Mentorship'],
    ['route' => 'income.growth-booster', 'label' => 'Growth Booster'],
    ['route' => 'income.wallet',         'label' => 'Wallet & Payouts'],
];
@endphp
<div class="flex flex-wrap gap-2 mb-6">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors
                  {{ request()->routeIs($tab['route'])
                      ? 'bg-brand-500 text-white shadow-sm'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
