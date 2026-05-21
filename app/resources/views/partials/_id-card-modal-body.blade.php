{{-- Fragment returned by DistributorDetailsController::show().
     Injected into the modal shell defined in _id-card-modal.blade.php.
     Read-only: no upload affordance, no edit. --}}
<div class="mb-4 pb-3 border-b border-gray-100">
    <p class="text-xs text-gray-500 uppercase tracking-wider font-semibold mb-1">Distributor</p>
    <p class="text-lg font-semibold text-gray-900">{{ $idCardStats['name'] }}</p>
    <p class="text-sm text-brand-600 font-mono tracking-wider mt-0.5">{{ $idCardStats['adn'] }}</p>
</div>

@include('partials._id-card-panel', [
    'idCardStats' => $idCardStats,
    'idPhotoUrl'  => $idPhotoUrl,
    'readonly'    => true,
])
