{{-- Dashboard hero — welcome, account status, identity chips and the
     referral-link widget. Renders for every signed-in user; the chips and
     referral card only for distributors. --}}
@php
    $initials = collect(explode(' ', trim((string) ($user->full_name ?? $user->email))))
        ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 text-white shadow-lg mb-6">
    <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-400/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 left-1/3 h-64 w-64 rounded-full bg-leaf-400/20 blur-3xl"></div>

    <div class="relative grid grid-cols-1 lg:grid-cols-[1fr_minmax(0,440px)] gap-6 p-6 sm:p-8">
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
                            <svg class="w-4 h-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z"/></svg>
                            ADN {{ $distributor->adn }}
                        </span>
                        @if($rankOn && $rankStatus?->currentRankName())
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sunrise-400/25 border border-sunrise-300/40 px-2.5 py-1 font-semibold">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/></svg>
                                {{ $rankStatus->currentRankName() }}
                            </span>
                        @endif
                        @if($title?->title)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-leaf-400/25 border border-leaf-300/40 px-2.5 py-1 font-semibold">
                                {{ $title->title }}
                            </span>
                        @endif
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-white/85">
                            Member since {{ $distributor->effective_date->format('d M Y') }}
                        </span>
                    </div>
                @endif
            </div>
        </div>

        @if($hasDistributorBlock)
            {{-- Referral-link card — same source and behaviour as before,
                 now on a glass panel inside the hero so distributors always
                 see their invite URL at the very top of the dashboard. --}}
            <div class="w-full rounded-2xl border-2 border-sunrise-400 bg-white/15 backdrop-blur-sm p-4 self-start shadow-lg shadow-sunrise-500/20 ring-4 ring-sunrise-400/20">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <p class="inline-flex items-center gap-1.5 text-xs text-white uppercase tracking-wider font-bold">
                        <svg class="w-4 h-4 text-sunrise-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                        My Referral Link
                    </p>
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-sunrise-400 text-brand-950">Personal invite</span>
                </div>
                <div class="flex items-stretch gap-2">
                    <input type="text" readonly value="{{ $inviteUrl }}"
                        class="flex-1 min-w-0 rounded-lg border-2 border-sunrise-300 bg-white px-2.5 py-2 text-xs font-mono font-semibold text-brand-900 focus:outline-none focus:ring-2 focus:ring-sunrise-400"
                        onclick="this.select()">
                    <button type="button"
                        onclick="navigator.clipboard.writeText('{{ $inviteUrl }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
                        class="px-4 rounded-lg bg-sunrise-500 hover:bg-sunrise-600 text-white text-xs font-bold shadow-md transition-colors">
                        Copy
                    </button>
                </div>
                @if($bothFull)
                    <p class="mt-2 text-[11px] text-sunrise-200">
                        <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-sunrise-400"></span>Direct slots full.</span>
                        <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}" class="text-white underline-offset-2 hover:underline">My Genos →</a>
                    </p>
                @else
                    <p class="mt-2 text-[11px] text-white/80">
                        Want a specific deeper slot?
                        <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}" class="text-white underline-offset-2 hover:underline">My Genos →</a>
                    </p>
                @endif
            </div>
        @endif
    </div>
</section>
