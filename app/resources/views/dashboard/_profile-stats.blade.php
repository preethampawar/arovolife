{{-- Profile Stats — the ADN card: 15-row stats + ID photo upload/crop. --}}
<div class="relative overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-white to-leaf-50/60 shadow-sm p-6">
    <span class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-brand-500 via-leaf-500 to-sunrise-500" aria-hidden="true"></span>
    <div class="flex items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-md shadow-brand-500/30">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z"/></svg>
            </span>
            <div>
                <p class="text-xs text-brand-800 uppercase tracking-wider font-semibold">Profile Stats</p>
                <p class="text-sm text-gray-800 mt-0.5">Your ADN card details, exactly as printed on your membership card.</p>
            </div>
        </div>
        <a href="{{ route('profile-stats.show') }}" target="_blank" rel="noopener"
            title="Open a printable copy of your profile stats — choose Save as PDF in the print dialog"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-brand-300 bg-white hover:bg-brand-50 text-xs font-medium text-brand-800 transition-colors shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
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
