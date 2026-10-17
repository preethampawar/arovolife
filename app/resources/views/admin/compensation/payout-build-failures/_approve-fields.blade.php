{{-- Inside an approve form: the approver has to say, knowingly, that the
     skipped distributors wait for the next batch (user 2026-10-17). The count
     goes with it so a skip recorded after this page loaded is refused. --}}
@if($skipped['count'] > 0)
<input type="hidden" name="skipped_count" value="{{ $skipped['count'] }}">
<label class="flex items-start gap-2 mb-2 max-w-sm text-xs text-red-800">
    <input type="checkbox" name="approve_without_skipped" value="1" required class="mt-0.5 rounded border-gray-300">
    <span>
        Approve without the {{ $skipped['count'] }} distributor(s) that could not be built.
        {{ $batch->batch_type === 'monthly'
            ? 'They are paid by next month’s batch, a month later.'
            : 'They are paid by next week’s batch.' }}
    </span>
</label>
@endif
