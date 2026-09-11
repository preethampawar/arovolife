@props(['user' => null, 'fallback' => '—'])

{{-- One side of a reported conversation. The name alone is not an identity
     here: several accounts are registered under the same company name, and a
     moderator reading "Arovolife Private Limited" on both rows cannot tell the
     reporter from the sender. So the ADN comes with it, linked to the admin
     record for that distributor. Staff accounts have no distributor row and
     render as the name alone. --}}
@php($partyDistributor = $user?->distributor)

@if($user === null)
    <span class="text-gray-600">{{ $fallback }}</span>
@elseif($partyDistributor === null)
    <span class="text-gray-900">{{ $user->full_name }}</span>
@else
    <a href="{{ route('admin.distributors.show', $partyDistributor->id) }}"
       class="text-gray-900 hover:text-brand-800">
        {{ $user->full_name }}
        <span class="block font-mono text-xs text-brand-700">{{ $partyDistributor->adn }}</span>
    </a>
@endif
