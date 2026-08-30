{{-- Profile Stats — the ADN card: 15-row stats + ID photo upload/crop. --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Profile Stats</p>
            <p class="text-sm text-gray-800 mt-1">Your ADN card details, exactly as printed on your membership card.</p>
        </div>
        <a href="{{ route('profile-stats.show') }}" target="_blank" rel="noopener"
            title="Open a printable copy of your profile stats — choose Save as PDF in the print dialog"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-xs font-medium text-gray-700 transition-colors shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
            Download PDF
        </a>
    </div>
    @include('partials._id-card-panel', [
        'idCardStats' => $idCardStats,
        'idPhotoUrl'  => $idPhotoUrl,
        'readonly'    => false,
    ])
</div>
