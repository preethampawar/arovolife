{{-- arovolife Family Card — compact face + links to the printable page and
     to My Profile → Family members. Display only (R-115). Included from
     dashboard/index.blade.php behind FamilyCardFeature.

     Expects: $user, $distributor. --}}
<div class="rounded-2xl border border-brand-200 bg-brand-50/60 shadow-sm p-6">
    <div class="flex items-center justify-between gap-3 mb-3">
        <p class="text-xs text-brand-700 uppercase tracking-wider font-semibold">Family Card</p>
        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-brand-50 text-brand-700 border border-brand-200">1 + 5</span>
    </div>

    @include('membership._family-card-front', [
        'compact' => true,
        'name' => $user->full_name,
        'adn' => $distributor->adn,
    ])

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('family-card.show') }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-xs font-semibold text-white transition-colors">
            <x-lucide-printer class="w-3.5 h-3.5" />
            Print / Download PDF
        </a>
        <a href="{{ route('profile.show') }}#family-members"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-brand-200 bg-white hover:bg-brand-50 text-xs font-semibold text-brand-700 transition-colors">
            <x-lucide-users class="w-3.5 h-3.5" />
            Manage family members
        </a>
    </div>
</div>
