<h3 class="text-sm font-semibold mb-3">Retry Daily Cut-off</h3>
<form method="POST" action="{{ route('admin.compensation.manual-controls.retry') }}"
      data-confirm="This will re-run the 23:59 cut-off for this distributor."
      data-confirm-title="Confirm: Retry Daily Cut-off"
      data-confirm-impact="GSB will be calculated and credited if not already done. If already credited, no duplicate credit will be issued.">
    @csrf
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN</label>
            <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Cut-off date</label>
            <input type="date" name="date" value="{{ $date ?? now()->toDateString() }}" required
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars)</label>
        <textarea name="reason" rows="2" required placeholder="e.g. Wallet write failed due to DB timeout — retrying after recovery"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 text-white text-sm font-medium hover:bg-brand-600">
        Preview &amp; Confirm &rarr;
    </button>
</form>
