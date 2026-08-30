{{--
    Arete Centres section header: page title plus the section tabs.
    The registry is always on; the Applications tab appears only while the
    applications flag is on and the viewer may review (zero trace otherwise).
    "Member directory" opens the members-only listing so staff see exactly
    what a distributor sees.
--}}
@php
    $areteTabs = array_values(array_filter([
        ['label' => 'Centres', 'route' => 'admin.arete-centres.index', 'match' => ['admin.arete-centres.index', 'admin.arete-centres.create', 'admin.arete-centres.edit']],
        \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\AreteCenterApplicationsFeature::class)
            && (auth()->user()?->can('adc.application.review') ?? false)
            ? ['label' => 'Applications', 'route' => 'admin.arete-centres.applications.index', 'match' => ['admin.arete-centres.applications.*']]
            : null,
    ]));
@endphp
<div class="mb-5">
    @hasSection('heading')
    <h2 class="text-xl sm:text-2xl font-bold text-gray-900 tracking-tight mb-3">@yield('heading')</h2>
    @endif
    <nav aria-label="Arete Centres sections" class="flex flex-wrap items-center gap-1 border-b border-gray-200">
        @foreach($areteTabs as $tab)
            @php $active = request()->routeIs(...$tab['match']); @endphp
            <a href="{{ route($tab['route']) }}" @if($active) aria-current="page" @endif
               class="px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
                      {{ $active ? 'border-brand-700 text-brand-800' : 'border-transparent text-gray-600 hover:text-gray-900 hover:border-gray-300' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
        <a href="{{ route('my.adc.directory') }}" target="_blank" rel="noopener"
           class="ml-auto px-3 py-2 text-sm text-gray-600 hover:text-gray-900">Member directory ↗</a>
    </nav>
</div>
