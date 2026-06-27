<h3 class="text-sm font-semibold text-red-700 mb-3">Freeze / Unfreeze GSB</h3>
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Freeze blocks future GSB credits without deactivating the account. GSB is still calculated but held. Unfreeze resumes normal payouts.
</div>
<form method="POST" action="{{ route('admin.compensation.manual-controls.freeze-gsb') }}"
      data-confirm="This will freeze or unfreeze GSB credits for this distributor."
      data-confirm-title="Confirm: Freeze / Unfreeze GSB"
      data-confirm-impact="All future GSB credits will be blocked (or unblocked) until this setting is changed again.">
    @csrf
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN <x-help-tip text="Arovolife Distributor Number of the distributor to freeze or unfreeze." /></label>
            <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Action <x-help-tip text="Freeze holds future GSB credits while still calculating them; Unfreeze resumes normal credits." /></label>
            <select name="freeze" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                <option value="freeze">Freeze GSB</option>
                <option value="unfreeze">Unfreeze GSB</option>
            </select>
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars) <x-help-tip text="Why GSB is being frozen or unfrozen. Recorded in the audit log." /></label>
        <textarea name="reason" rows="2" required
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700">
        Preview &amp; Confirm &rarr;
    </button>
</form>
