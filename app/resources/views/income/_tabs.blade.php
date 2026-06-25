@php
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Laravel\Pennant\Feature;

$tabs = [
    ['route' => 'income.dashboard',   'label' => 'Dashboard', 'visible' => true],
    ['route' => 'income.genos-bv',    'label' => 'Genos BV',  'visible' => true],
    ['route' => 'income.gsb-history', 'label' => 'GSB History', 'visible' => true],
    ['route' => 'income.mentorship',  'label' => 'Mentorship', 'visible' => Feature::for(null)->active(MentorshipBonusFeature::class)],
    ['route' => 'income.growth-booster', 'label' => 'Growth Booster', 'visible' => Feature::for(null)->active(GrowthBoosterBonusFeature::class)],
    ['route' => 'income.rank-bonus',  'label' => 'Rank Bonus', 'visible' => Feature::for(null)->active(RankBonusFeature::class)],
    ['route' => 'income.fortune-bonus', 'label' => 'Fortune Bonus', 'visible' => Feature::for(null)->active(FortuneBonusFeature::class)],
    ['route' => 'income.wallet',         'label' => 'Wallet & Payouts', 'visible' => true],
];
@endphp
<div class="flex flex-wrap gap-2 mb-6">
    @foreach($tabs as $tab)
        @if($tab['visible'])
        <a href="{{ route($tab['route']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors
                  {{ request()->routeIs($tab['route'])
                      ? 'bg-brand-500 text-white shadow-sm'
                      : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $tab['label'] }}
        </a>
        @endif
    @endforeach
</div>
