{{--
    Compensation sub-navigation.

    Rendered by the admin layout on every `admin.compensation.*` (and
    `admin.lifetime-awards.*`) page so a report is never a dead end — before
    this existed the only way back to the Overview was the browser Back button.

    Groups mirror how the plan actually runs: the daily GSB/MSB engine, the
    monthly bonus engines, the weekly payout, and the plan configuration.
    Feature-gated engines only appear when their flag is on, exactly as the
    Overview quick-links did.
--}}
@php
    use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
    use App\Modules\Shared\Features\FortuneBonusFeature;
    use App\Modules\Shared\Features\GenosSalesBonusFeature;
    use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
    use App\Modules\Shared\Features\LifetimeAwardsFeature;
    use App\Modules\Shared\Features\MentorshipBonusFeature;
    use App\Modules\Shared\Features\RankBonusFeature;
    use Laravel\Pennant\Feature;

    $gsbOn = Feature::for(null)->active(GenosSalesBonusFeature::class);
    $msbOn = Feature::for(null)->active(MentorshipBonusFeature::class);
    $gbbOn = Feature::for(null)->active(GrowthBoosterBonusFeature::class);
    $rankOn = Feature::for(null)->active(RankBonusFeature::class);
    $fortuneOn = Feature::for(null)->active(FortuneBonusFeature::class);
    $adcOn = Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class);
    $awardsOn = Feature::for(null)->active(LifetimeAwardsFeature::class);

    /**
     * Each entry is either a plain link (`route`) or a dropdown (`items`).
     * `match` is the route-name prefix that lights the item up; it defaults
     * to the route name itself.
     *
     * @var array<int, array{label: string, route?: string, match?: string, items?: array<int, array<string, string>>}>
     */
    $compNavGroups = [
        ['label' => 'Overview', 'route' => 'admin.compensation.overview', 'match' => 'admin.compensation.overview'],

        ['label' => 'Daily engine', 'items' => array_values(array_filter(array_merge(
            $gsbOn ? [
                ['heading' => 'GSB — Genos Sales Bonus'],
                ['label' => 'Daily cut-offs', 'route' => 'admin.compensation.daily-cutoffs.index', 'match' => 'admin.compensation.daily-cutoffs'],
                ['label' => 'GSB Calculation', 'route' => 'admin.compensation.gsb-calculation.index', 'match' => 'admin.compensation.gsb-calculation'],
                ['label' => 'GSB Input & Output / Day', 'route' => 'admin.compensation.gsb-input-output.index', 'match' => 'admin.compensation.gsb-input-output'],
            ] : [],
            $msbOn ? [
                ['heading' => 'MSB — Mentorship Bonus'],
                ['label' => 'MSB Calculation', 'route' => 'admin.compensation.msb-calculation.index', 'match' => 'admin.compensation.msb-calculation'],
                ['label' => 'MSB Input & Output / Day', 'route' => 'admin.compensation.msb-input-output.index', 'match' => 'admin.compensation.msb-input-output'],
            ] : [],
            [
                ['heading' => 'Inputs'],
                ['label' => 'Genos BV Transactions', 'route' => 'admin.compensation.genos-transactions.index', 'match' => 'admin.compensation.genos-transactions'],
                $gsbOn ? ['label' => 'Personal BV Topups', 'route' => 'admin.compensation.personal-bv-topups.index', 'match' => 'admin.compensation.personal-bv-topups'] : null,
                $gsbOn ? ['label' => 'Carry-forwards', 'route' => 'admin.compensation.carry-forwards.index', 'match' => 'admin.compensation.carry-forwards'] : null,
            ],
        )))],

        ['label' => 'Monthly bonuses', 'items' => array_values(array_filter([
            ['heading' => 'Runs'],
            $gbbOn ? ['label' => 'Growth Booster Bonus', 'route' => 'admin.compensation.gbb.index', 'match' => 'admin.compensation.gbb.'] : null,
            $rankOn ? ['label' => 'Rank Bonus', 'route' => 'admin.compensation.rank-bonus.index', 'match' => 'admin.compensation.rank-bonus'] : null,
            $fortuneOn ? ['label' => 'Fortune Bonus', 'route' => 'admin.compensation.fortune-bonus.index', 'match' => 'admin.compensation.fortune-bonus'] : null,
            $adcOn ? ['label' => 'ADC Bonus', 'route' => 'admin.compensation.adc-bonus.index', 'match' => 'admin.compensation.adc-bonus.index'] : null,
            $awardsOn ? ['label' => 'Lifetime Awards', 'route' => 'admin.lifetime-awards.index', 'match' => 'admin.lifetime-awards'] : null,
            ['heading' => 'Calculation reports'],
            $gbbOn ? ['label' => 'GBB Calculation', 'route' => 'admin.compensation.gbb-calculation.index', 'match' => 'admin.compensation.gbb-calculation'] : null,
            $gbbOn ? ['label' => 'GBB Input & Output / Month', 'route' => 'admin.compensation.gbb-input-output.index', 'match' => 'admin.compensation.gbb-input-output'] : null,
            $rankOn ? ['label' => 'Rank Bonus Calculation', 'route' => 'admin.compensation.rb-calculation.index', 'match' => 'admin.compensation.rb-calculation'] : null,
            $rankOn ? ['label' => 'RB Input & Output / Month', 'route' => 'admin.compensation.rb-input-output.index', 'match' => 'admin.compensation.rb-input-output'] : null,
            $fortuneOn ? ['label' => 'Fortune Bonus Calculation', 'route' => 'admin.compensation.fb-calculation.index', 'match' => 'admin.compensation.fb-calculation'] : null,
            $awardsOn ? ['label' => 'Awards & Rewards', 'route' => 'admin.compensation.aw-rw-calculation.index', 'match' => 'admin.compensation.aw-rw-calculation'] : null,
            $adcOn ? ['label' => 'ADC Calculation', 'route' => 'admin.compensation.adc-calculation.index', 'match' => 'admin.compensation.adc-calculation'] : null,
        ]))],

        ['label' => 'Payouts', 'route' => 'admin.compensation.weekly-payouts.index', 'match' => 'admin.compensation.weekly-payouts'],

        ['label' => 'Plan & controls', 'items' => array_values(array_filter([
            $gsbOn ? ['label' => 'Manual Controls', 'route' => 'admin.compensation.manual-controls.index', 'match' => 'admin.compensation.manual-controls'] : null,
            ['label' => 'Engine Runs', 'route' => 'admin.compensation.engine-runs.index', 'match' => 'admin.compensation.engine-runs'],
            ['label' => 'Plan Settings', 'route' => 'admin.compensation.plan-settings.index', 'match' => 'admin.compensation.plan-settings'],
        ]))],
    ];

    $compNavIsActive = static fn (array $item): bool => isset($item['match'])
        && request()->routeIs($item['match'].'*');
@endphp

{{-- The layout wraps this and the page header in one sticky container, so the
     bar travels with the header instead of guessing its height as an offset. --}}
<nav aria-label="Compensation sections" data-comp-nav
     class="bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center gap-1 py-2">
        @foreach($compNavGroups as $group)
            @if(isset($group['route']))
                @php $active = $compNavIsActive($group); @endphp
                <a href="{{ route($group['route']) }}"
                   @if($active) aria-current="page" @endif
                   class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                          {{ $active ? 'bg-slate-800 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                    {{ $group['label'] }}
                </a>
            @else
                @php
                    $groupActive = collect($group['items'])->contains($compNavIsActive);
                @endphp
                <details class="relative" data-comp-nav-group>
                    <summary class="list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none
                                    flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors
                                    {{ $groupActive ? 'bg-slate-800 text-white' : 'text-gray-700 hover:bg-gray-100' }}">
                        {{ $group['label'] }}
                        <span class="text-[9px] leading-none opacity-70">▼</span>
                    </summary>
                    <div class="absolute left-0 top-full mt-1 z-30 w-72 rounded-xl border border-gray-200 bg-white shadow-lg py-2">
                        @foreach($group['items'] as $item)
                            @if(isset($item['heading']))
                                <p class="px-4 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-600 whitespace-nowrap">{{ $item['heading'] }}</p>
                            @else
                                @php $active = $compNavIsActive($item); @endphp
                                <a href="{{ route($item['route']) }}"
                                   @if($active) aria-current="page" @endif
                                   class="block px-4 py-2 text-xs whitespace-nowrap transition-colors
                                          {{ $active
                                             ? 'bg-indigo-100 text-indigo-700 font-semibold'
                                             : 'text-gray-700 hover:bg-indigo-50 hover:text-indigo-700' }}">
                                    {{ $item['label'] }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endif
        @endforeach
    </div>
</nav>

{{-- Vanilla JS (the admin layout deliberately avoids Alpine): one dropdown
     open at a time, and close on outside click or Escape. --}}
<script>
    (function () {
        const nav = document.querySelector('[data-comp-nav]');
        if (! nav) return;

        const groups = nav.querySelectorAll('[data-comp-nav-group]');

        groups.forEach((group) => {
            group.addEventListener('toggle', () => {
                if (! group.open) return;
                groups.forEach((other) => { if (other !== group) other.open = false; });
            });
        });

        const closeAll = () => groups.forEach((group) => { group.open = false; });

        document.addEventListener('click', (event) => {
            if (! nav.contains(event.target)) closeAll();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeAll();
        });
        nav.addEventListener('click', (event) => {
            if (event.target.closest('a')) closeAll();
        });
    })();
</script>
