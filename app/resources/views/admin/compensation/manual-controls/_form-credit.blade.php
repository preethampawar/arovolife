<h3 class="text-sm font-semibold mb-3">Manual GSB Credit</h3>
<div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
    Only use this when Retry fails. This bypasses the normal GSB calculation.
</div>
<form method="POST" action="{{ route('admin.compensation.manual-controls.credit') }}"
      data-confirm="This will credit a custom amount to the distributor's wallet."
      data-confirm-title="Confirm: Manual GSB Credit"
      data-confirm-impact="The amount will be credited immediately and is not reversible except via the Reverse action.">
    @csrf
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN</label>
            <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Amount (Rs., min Rs. 1)</label>
            <input type="number" name="amount" step="0.01" min="1" required placeholder="e.g. 1000.00"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars)</label>
        <textarea name="reason" rows="2" required
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 text-white text-sm font-medium hover:bg-brand-600">
        Preview &amp; Confirm &rarr;
    </button>
</form>
