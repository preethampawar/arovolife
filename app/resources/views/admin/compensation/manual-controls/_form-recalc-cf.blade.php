<h3 class="text-sm font-semibold mb-3">Recalculate Carry-forward</h3>
<form method="POST" action="{{ route('admin.compensation.manual-controls.recalc-cf') }}"
      data-confirm="This will log a carry-forward recalculation request."
      data-confirm-title="Confirm: Recalculate Carry-forward"
      data-confirm-impact="The carry-forward entry for this distributor will be flagged for rebuild. Full rebuild requires the GSB engine to be active.">
    @csrf
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN <x-help-tip text="Arovolife Distributor Number whose carry-forward BV will be flagged for recalculation." /></label>
        <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars) <x-help-tip text="Why the carry-forward needs recalculating. Recorded in the audit log." /></label>
        <textarea name="reason" rows="2" required
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 text-white text-sm font-medium hover:bg-brand-600">
        Preview &amp; Confirm &rarr;
    </button>
</form>
