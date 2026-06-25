<h3 class="text-sm font-semibold mb-3">Force Weekly Payout</h3>
<form method="POST" action="{{ route('admin.compensation.manual-controls.force-payout') }}"
      data-confirm="This will log a force-payout request for this distributor."
      data-confirm-title="Confirm: Force Weekly Payout"
      data-confirm-impact="The payout batch will run for this distributor at the next scheduled time. Use only if the automated Tuesday batch skipped this distributor.">
    @csrf
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN</label>
        <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
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
