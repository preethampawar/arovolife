{{-- Dashboard hero — welcome, account status, identity chips and the
     repurchase-cycle panel. Renders for every signed-in user; the chips and
     the panel only for distributors. --}}
@php
    $initials = collect(explode(' ', trim((string) ($user->full_name ?? $user->email))))
        ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');

    // The hero's right-hand panel is the repurchase cycle card. It is null
    // when the engine flag is off or the income tables are unreadable, and
    // then the hero must collapse to one column rather than leave a 440px
    // hole where the panel used to be.
    $heroAside = $hasDistributorBlock && ($repurchaseCard ?? null) !== null;
@endphp
<section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 text-white shadow-lg mb-6">
    <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-400/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 left-1/3 h-64 w-64 rounded-full bg-leaf-400/20 blur-3xl"></div>

    <div class="relative grid grid-cols-1 {{ $heroAside ? 'lg:grid-cols-[1fr_minmax(0,440px)]' : '' }} gap-6 p-6 sm:p-8">
        <div class="flex items-start gap-4 sm:gap-5 min-w-0">
            <div class="shrink-0">
                @if($hasDistributorBlock && $idPhotoUrl)
                    <img src="{{ $idPhotoUrl }}" alt="" class="h-16 w-16 sm:h-20 sm:w-20 rounded-2xl object-cover ring-2 ring-white/40 shadow-md">
                @else
                    <div class="h-16 w-16 sm:h-20 sm:w-20 rounded-2xl bg-white/15 ring-2 ring-white/30 flex items-center justify-center text-xl sm:text-2xl font-bold tracking-wide">
                        {{ $initials ?: '•' }}
                    </div>
                @endif
            </div>
            <div class="min-w-0">
                @if($hasDistributorBlock)
                    <a href="{{ route('dashboard.documents') }}" class="inline-flex items-center text-xs font-medium text-white/80 hover:text-white hover:underline mb-1.5">
                        Manage my KYC documents →
                    </a>
                @endif
                <h1 class="text-2xl sm:text-3xl font-bold leading-tight mb-2 truncate">Welcome, {{ $user->full_name ?? $user->email }}</h1>
                <p class="text-sm text-white/85 flex flex-wrap items-center gap-2">
                    <span>Status:</span>
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border {{ $accountStatus['class'] }}">
                        {{ $accountStatus['label'] }}
                    </span>
                </p>
                @if($hasDistributorBlock)
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span class="inline-flex items-center gap-2 rounded-full bg-white text-brand-900 px-3 py-1 text-sm font-mono font-bold tracking-widest shadow-md ring-2 ring-sunrise-400 ring-offset-2 ring-offset-brand-800">
                            <x-lucide-id-card class="w-4 h-4 text-brand-600" />
                            ADN {{ $distributor->adn }}
                        </span>
                        {{-- Rank. $rankStatus is null both when the feature is off
                             and when the income queries failed, and in neither case
                             do we know the rank — so "No Rank" is only shown once we
                             have a real RankStatus whose currentRankName() is null,
                             which means the rank is genuinely not yet achieved. --}}
                        @if($rankOn && $rankStatus !== null)
                            @php $currentRankName = $rankStatus->currentRankName(); @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold {{ $currentRankName ? 'bg-sunrise-400/25 border border-sunrise-300/40' : 'bg-white/10 border border-white/20 text-white/70' }}">
                                <x-lucide-trophy class="w-3.5 h-3.5" />
                                {{ $currentRankName ?? 'No Rank' }}
                            </span>
                        @endif
                        {{-- Title. Same rule: $title is null only when the income
                             queries failed; a TitleResult with a null title is a
                             distributor who has not reached the first threshold. --}}
                        @if($title !== null)
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold {{ $title->title ? 'bg-leaf-400/25 border border-leaf-300/40' : 'bg-white/10 border border-white/20 text-white/70' }}">
                                {{ $title->title ?? 'No Title' }}
                            </span>
                        @endif
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-white/85">
                            Member since {{ $distributor->effective_date->format('d M Y') }}
                        </span>
                    </div>
                @endif
            </div>
        </div>

        @if($heroAside)
            {{-- Repurchase cycle. It sits where the referral-link card used
                 to, because it is the one thing on the dashboard with a
                 deadline attached and the hero is what a distributor reads
                 first. The referral link moved to the right-hand column,
                 which has no clock on it. The card brings its own light
                 surface, so it stays legible against the dark hero. --}}
            <div class="w-full self-start">
                @include('dashboard._repurchase-cycle', ['card' => $repurchaseCard])
            </div>
        @endif
    </div>
</section>
