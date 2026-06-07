@extends('layouts.app')
@section('title', 'My Addresses')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">My Addresses</h1>
    <p class="text-sm text-gray-600 mb-6">Save your delivery addresses so you can reuse them at checkout.</p>

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Saved addresses --}}
    @if($addresses->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center mb-6">
            <p class="text-gray-500">You haven't saved any addresses yet. Add one below.</p>
        </div>
    @else
        <div class="space-y-3 mb-8">
            @foreach($addresses as $address)
            <div class="bg-white rounded-2xl border {{ $address->is_default ? 'border-brand-300 ring-1 ring-brand-200' : 'border-gray-200' }} p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            @if($address->label)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">{{ $address->label }}</span>
                            @endif
                            @if($address->is_default)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-200">Default</span>
                            @endif
                        </div>
                        <p class="text-sm font-medium text-gray-900">{{ $address->name }} · {{ $address->phone_e164 }}</p>
                        <p class="text-sm text-gray-600 mt-0.5">{{ $address->oneLine() }}</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0 text-sm">
                        @unless($address->is_default)
                        <form method="POST" action="{{ route('addresses.set-default', $address) }}">
                            @csrf
                            <button type="submit" class="text-brand-600 hover:text-brand-700 font-medium whitespace-nowrap">Set default</button>
                        </form>
                        @endunless
                        <button type="button" data-addr-edit="{{ $address->id }}" class="text-gray-600 hover:text-gray-800">Edit</button>
                        <form method="POST" action="{{ route('addresses.destroy', $address) }}"
                              data-confirm="Delete this saved address?"
                              data-confirm-title="Delete address"
                              data-confirm-impact="This removes the saved address. It won't affect any past orders.">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-600 hover:text-red-700">Delete</button>
                        </form>
                    </div>
                </div>

                {{-- Inline edit form (hidden until "Edit") --}}
                <div data-addr-editform="{{ $address->id }}" hidden class="mt-5 pt-5 border-t border-gray-100">
                    @include('shop.addresses._form', [
                        'action' => route('addresses.update', $address),
                        'method' => 'PATCH',
                        'address' => $address,
                        'presetLabels' => $presetLabels,
                        'submitLabel' => 'Update address',
                        'cancelTarget' => $address->id,
                    ])
                </div>
            </div>
            @endforeach
        </div>
    @endif

    {{-- Add new address --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-900 mb-4">Add a new address</h2>
        @include('shop.addresses._form', [
            'action' => route('addresses.store'),
            'method' => 'POST',
            'address' => null,
            'presetLabels' => $presetLabels,
            'submitLabel' => 'Save address',
        ])
    </div>
</div>

<script>
(function () {
    // Toggle each card's inline edit form.
    document.querySelectorAll('[data-addr-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-addr-edit');
            var form = document.querySelector('[data-addr-editform="' + id + '"]');
            if (form) { form.hidden = !form.hidden; }
        });
    });
    document.querySelectorAll('[data-addr-cancel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.querySelector('[data-addr-editform="' + btn.getAttribute('data-addr-cancel') + '"]');
            if (form) { form.hidden = true; }
        });
    });
})();
</script>
@endsection
