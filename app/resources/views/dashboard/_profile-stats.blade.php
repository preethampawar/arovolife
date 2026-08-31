{{-- Profile Stats — the ADN card: 15-row stats + ID photo upload/crop. --}}
<div class="relative overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-white to-leaf-50/60 shadow-sm p-6">
    <span class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-brand-500 via-leaf-500 to-sunrise-500" aria-hidden="true"></span>
    <div class="flex items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30">
                <x-lucide-id-card class="w-5 h-5" />
            </span>
            <div>
                <p class="text-xs text-brand-800 uppercase tracking-wider font-semibold">Profile Stats</p>
                <p class="text-sm text-gray-800 mt-0.5">Your ADN card details, exactly as printed on your membership card.</p>
            </div>
        </div>
        <a href="{{ route('profile-stats.show') }}" target="_blank" rel="noopener"
            title="Open a printable copy of your profile stats — choose Save as PDF in the print dialog"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-brand-300 bg-white hover:bg-brand-50 text-xs font-medium text-brand-800 transition-colors shrink-0">
            <x-lucide-upload class="w-3.5 h-3.5" />
            Download PDF
        </a>
    </div>
    @include('partials._id-card-panel', [
        'idCardStats' => $idCardStats,
        'idPhotoUrl'  => $idPhotoUrl,
        'readonly'    => false,
        'colorful'    => true,
    ])
</div>
