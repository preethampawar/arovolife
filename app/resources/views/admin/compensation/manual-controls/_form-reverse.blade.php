<h3 class="text-sm font-semibold text-red-700 mb-3">Reverse GSB Credit</h3>
<div class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800">
    This writes a debit entry equal to the net GSB for the given date. The distributor's wallet balance will decrease. Only reverse if the credit was issued in error.
</div>
<form method="POST" action="{{ route('admin.compensation.manual-controls.reverse') }}"
      data-confirm="This will write a debit entry reversing the GSB credit for this distributor."
      data-confirm-title="Confirm: Reverse GSB Credit"
      data-confirm-impact="The full net GSB amount for the specified date will be debited from the wallet. This cannot be undone without a Manual Credit.">
    @csrf
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN</label>
            <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Cut-off date to reverse</label>
            <input type="date" name="date" value="{{ $date ?? now()->toDateString() }}" required
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars)</label>
        <textarea name="reason" rows="2" required
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700">
        Preview &amp; Confirm &rarr;
    </button>
</form>
