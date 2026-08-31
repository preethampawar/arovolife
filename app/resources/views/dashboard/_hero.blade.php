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
                            <x-lucide-id-card class="w-4 h-4 text-brand-600" />
                            ADN {{ $distributor->adn }}
                        </span>
                        @if($rankOn && $rankStatus?->currentRankName())
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sunrise-400/25 border border-sunrise-300/40 px-2.5 py-1 font-semibold">
                                <x-lucide-trophy class="w-3.5 h-3.5" />
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
            <div class="w-full rounded-2xl border border-white/20 bg-white/10 backdrop-blur-sm p-4 self-start">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <p class="text-[11px] text-white/90 uppercase tracking-wider font-semibold">My Referral Link</p>
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-white text-brand-700">Personal invite</span>
                </div>
                <div class="flex items-stretch gap-2">
                    <input type="text" readonly value="{{ $inviteUrl }}"
                        class="flex-1 min-w-0 rounded-lg border border-white/30 bg-white px-2.5 py-1.5 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-sunrise-400"
                        onclick="this.select()">
                    <button type="button"
                        onclick="navigator.clipboard.writeText('{{ $inviteUrl }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
                        class="px-3 rounded-lg bg-sunrise-500 hover:bg-sunrise-600 text-white text-xs font-semibold transition-colors">
                        Copy
                    </button>
                </div>
                @if($bothFull)
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[11px] text-sunrise-200">
                        <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-sunrise-400"></span>Direct slots full.</span>
                        <span class="inline-flex flex-wrap items-center gap-2">
                        <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-brand-800 shadow-md ring-2 ring-sunrise-400 hover:bg-sunrise-50 transition-colors">
                            <x-lucide-network class="w-4 h-4 text-brand-600" />
                            My Genos →
                        </a>
                        <a href="{{ route('tree.sponsorship') }}"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-brand-800 shadow-md ring-2 ring-leaf-400 hover:bg-leaf-50 transition-colors">
                            <x-lucide-users class="w-4 h-4 text-leaf-600" />
                            Direct referrals →
                        </a>
                        </span>
                    </div>
                @else
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[11px] text-white/80">
                        <span>Want a specific deeper slot?</span>
                        <span class="inline-flex flex-wrap items-center gap-2">
                        <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-brand-800 shadow-md ring-2 ring-sunrise-400 hover:bg-sunrise-50 transition-colors">
                            <x-lucide-network class="w-4 h-4 text-brand-600" />
                            My Genos →
                        </a>
                        <a href="{{ route('tree.sponsorship') }}"
                           class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-brand-800 shadow-md ring-2 ring-leaf-400 hover:bg-leaf-50 transition-colors">
                            <x-lucide-users class="w-4 h-4 text-leaf-600" />
                            Direct referrals →
                        </a>
                        </span>
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
