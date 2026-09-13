{{-- My Referral Link — the distributor's personal invite URL.

     Lived on the hero's glass panel until the repurchase cycle took that
     slot. Same URL, same copy button, same slot-aware hint; restyled as the
     plain white card the rest of this column uses (_messages, _cooling-off)
     because it now sits on the page background, not on the dark hero.

     Expects: $inviteUrl, $bothFull, $maxObservedDepth — all set in
     dashboard/index.blade.php. --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-center justify-between gap-3 mb-3">
        <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">My Referral Link</p>
        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-brand-50 text-brand-700 border border-brand-200">Personal invite</span>
    </div>

    <div class="flex items-stretch gap-2">
        <input type="text" readonly value="{{ $inviteUrl }}" aria-label="My referral link"
            class="flex-1 min-w-0 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-400"
            onclick="this.select()">
        <button type="button"
            onclick="navigator.clipboard.writeText('{{ $inviteUrl }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
            class="px-3 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-xs font-semibold transition-colors">
            Copy
        </button>
    </div>

    @if($bothFull)
        <p class="mt-3 inline-flex items-center gap-1.5 text-[11px] text-gray-700">
            <span class="w-1.5 h-1.5 rounded-full bg-sunrise-500"></span>Direct slots full.
        </p>
    @else
        <p class="mt-3 text-[11px] text-gray-600">Want a specific deeper slot?</p>
    @endif

    <div class="mt-2 flex flex-wrap items-center gap-2">
        <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}"
           class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 hover:bg-gray-50 transition-colors">
            <x-lucide-network class="w-4 h-4 text-brand-600" />
            My Genos →
        </a>
        <a href="{{ route('tree.sponsorship') }}"
           class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 hover:bg-gray-50 transition-colors">
            <x-lucide-users class="w-4 h-4 text-leaf-600" />
            Direct referrals →
        </a>
    </div>
</div>
