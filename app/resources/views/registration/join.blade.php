@extends('layouts.app')
@section('title', 'Join arovolife')

@section('content')
<div class="max-w-md mx-auto">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Join arovolife</h1>
    <p class="text-sm text-gray-600 mb-6">
        Enter the ADN of the Direct Seller who invited you, plus the placement ADN under
        whose binary tree you'd like to be placed. They're often the same.
        Your sponsor can find both numbers in their dashboard.
    </p>

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('join.submit') }}"
          class="space-y-5 bg-white rounded-2xl border border-gray-200 p-6 sm:p-8">
        @csrf

        <div>
            <label for="sponsor_adn" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Sponsor ADN <span class="text-red-700">*</span></label>
            <input type="text" id="sponsor_adn" name="sponsor_adn" required
                   value="{{ $sponsorAdn }}"
                   placeholder="AL-1234567890"
                   autocomplete="off"
                   spellcheck="false"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono uppercase tracking-widest focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
            <p class="mt-1.5 text-xs text-gray-500">The Direct Seller who invited you.</p>
        </div>

        <div>
            <label for="placement_adn" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Placement ADN <span class="text-red-700">*</span></label>
            <input type="text" id="placement_adn" name="placement_adn" required
                   value="{{ $placementAdn }}"
                   placeholder="AL-1234567890"
                   autocomplete="off"
                   spellcheck="false"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono uppercase tracking-widest focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
            <p class="mt-1.5 text-xs text-gray-500">Often the same as the Sponsor ADN. Ask your sponsor if you're unsure.</p>
        </div>

        <button type="submit"
            class="w-full rounded-full bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 shadow-lg shadow-brand-500/30">
            Continue to Orientation →
        </button>
    </form>

    <p class="mt-6 text-center text-xs text-gray-500">
        Don't have a sponsor's ADN?
        <a href="{{ route('contact.show', ['reason' => 'join_us']) }}" class="text-brand-700 hover:text-brand-800 font-medium">Contact us</a>
        and we'll connect you with one.
    </p>
</div>

<script>
// Force-uppercase + auto-strip whitespace as the user types so they can paste
// "al-1234..." without an upstream "format invalid" error from the backend.
['sponsor_adn', 'placement_adn'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => {
        el.value = el.value.toUpperCase().replace(/\s+/g, '');
    });
});
</script>
@endsection
