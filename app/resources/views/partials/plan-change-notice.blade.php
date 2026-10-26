{{-- Compensation-plan change notice (plan page §12, R-123). Rendered only when
     the view composer in ConsentServiceProvider finds a change this distributor
     has not agreed to; $planChange is null otherwise and nothing is output.
     The entries are cut from the published plan page body, the same purified
     HTML /p/compensation renders. --}}
@if($planChange !== null)
<div id="plan-change-notice" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
     role="dialog" aria-modal="true" aria-labelledby="plan-change-notice-title">
    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl">
        <div class="border-b border-gray-200 px-6 pt-6 pb-4">
            <h2 id="plan-change-notice-title" class="text-lg font-semibold text-gray-900">
                The arovolife Compensation Plan has changed
            </h2>
            <p class="mt-2 text-sm text-gray-700">
                We have changed the Compensation Plan. Please read the changes below. These changes already apply. Each one applies from
                the day after it was published on the plan page, unless it gives a later date, and only to sales and bonus periods from
                then on. A change never applies to the past.
            </p>
        </div>

        <div class="overflow-y-auto px-6 py-4 text-sm text-gray-700 [&_a]:text-brand-700 [&_a]:underline [&_li]:my-1 [&_p]:my-2 [&_strong]:font-semibold [&_strong]:text-gray-900 [&_ul]:list-disc [&_ul]:pl-5">
            @forelse($planChange->entries as $entry)
                <h3 class="mt-3 text-sm font-semibold text-gray-900 first:mt-0">{{ $entry->label }}</h3>
                {!! $entry->html !!}
            @empty
                <p>The Compensation Plan has changed. Please read the full plan before you continue.</p>
            @endforelse

            <p class="mt-4">
                <a href="{{ url('/p/compensation') }}" target="_blank" rel="noopener">Read the full plan</a>
            </p>

            <details class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                <summary class="cursor-pointer font-medium text-gray-900">I do not agree — what can I do?</summary>
                <p>
                    You can end your agreement with arovolife by giving us written notice, under section 12 of the Direct Seller Agreement.
                    You can send it by email to <a href="mailto:{{ config('arovolife.support_email') }}">{{ config('arovolife.support_email') }}</a>
                    or through <a href="{{ url('/contact-us') }}">Contact us</a>,
                    or hand it in or send it by registered post to our registered office, as section 22 of the Agreement provides.
                    Products you bought may be returned in saleable condition within 30 days under the buy-back terms in section 8 of the Agreement.
                    If you keep your account, these changes apply to you from the day they take effect, even if you do not click "I have read the changes and agree".
                    If you have a question or a complaint, use <a href="{{ url('/p/grievance') }}">Grievance Redressal</a>.
                </p>
            </details>

            @if($planChange->coolingOffEndsAt !== null)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                    <p>
                        You have 30 days from the date of approval to cancel your Direct Seller agreement and receive a full refund of any product purchases made during this period.
                        This is your cooling-off period under the Consumer Protection (Direct Selling) Rules, 2021.
                    </p>
                    <p>
                        Cooling-off ends on {{ $planChange->coolingOffEndsAt->format('d M Y') }}
                        ({{ max(0, (int) ceil(now()->diffInDays($planChange->coolingOffEndsAt))) }} days left).
                        <a href="{{ route('cooling-off.show') }}">Cancel my agreement</a>
                    </p>
                </div>
            @endif
        </div>

        <div class="border-t border-gray-200 px-6 py-4">
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <form method="POST" action="{{ route('plan-changes.later') }}">
                    @csrf
                    <input type="hidden" name="hash" value="{{ $planChange->hashHex }}">
                    <button type="submit"
                        class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 sm:w-auto">
                        Remind me later
                    </button>
                </form>
                <form method="POST" action="{{ route('plan-changes.accept') }}">
                    @csrf
                    <input type="hidden" name="hash" value="{{ $planChange->hashHex }}">
                    <button type="submit"
                        class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 sm:w-auto">
                        I have read the changes and agree
                    </button>
                </form>
            </div>
            <p class="mt-3 text-xs text-gray-600">
                If you choose "Remind me later", we will show this again the next time you sign in.
                If you have not confirmed within 3 days, we will also email it to you.
            </p>
        </div>
    </div>
</div>
@endif
