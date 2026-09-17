<h3 class="text-sm font-semibold text-red-700 mb-3">Request GSB Reversal</h3>
<div class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800">
    <strong>This raises a request; it does not move money.</strong> A second admin has to approve it, and it cannot be
    you. Once approved it writes a debit equal to the net GSB for that date, the wallet balance drops, and
    <strong>nothing in the platform can put the credit back</strong> &mdash; a reversed day is settled for good, and even
    a retry will not restore it. Only request a reversal if the credit was issued in error.
</div>
<form method="POST" action="{{ route('admin.compensation.manual-controls.reverse') }}"
      data-confirm="This will raise a reversal request for a second admin to approve. No money moves yet."
      data-confirm-title="Confirm: Request GSB Reversal"
      data-confirm-impact="Nothing is debited by this step. The request appears on this page for an approver who is not you; on approval the full net GSB amount for that date is debited from the wallet and nothing in the admin panel can put it back — a reversal made in error is a platform-team job.">
    @csrf
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Distributor ADN <x-help-tip text="Arovolife Distributor Number whose GSB credit will be reversed (debited)." /></label>
            <input type="text" name="adn" value="{{ $adn ?? '' }}" required placeholder="e.g. AV-00042"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Cut-off date to reverse <x-help-tip text="The date whose net GSB credit will be debited back from the wallet." /></label>
            <input type="date" name="date" value="{{ $date ?? now()->toDateString() }}" required
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars) <x-help-tip text="Why the GSB credit should be reversed. This is what the approver reads before signing off, and it is recorded in the audit log." /></label>
        <textarea name="reason" rows="2" required
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none"></textarea>
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700">
        Preview &amp; Request &rarr;
    </button>
</form>
