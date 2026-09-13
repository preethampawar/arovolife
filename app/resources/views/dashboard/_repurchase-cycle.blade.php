{{--
    Repurchase cycle status card.

    Answers one question at a glance: where am I in my repurchase window, and
    what is still outstanding? Four states, because they are four different
    answers — not qualified yet, running, met, and missed — and blurring them
    is what makes a status card useless.

    Everything here is a fact the distributor cannot get from another line of
    the card: the ring carries the days left (so the header chip only appears
    for the states the ring cannot say — completed, missed, not started), the
    progress row carries the BV required, and the window length rides on the
    dates line. The three-column start/end/required list and the separate
    window note said all of that a second time, in twice the height.

    The ring geometry matches resources/views/dashboard/_cooling-off.blade.php
    so the platform has one visual language for "time remaining".

    Rendered in the dashboard hero. Expects: $card (RepurchaseCycleCard|null).
    Null means the engine flag is off or the income tables are unreadable —
    either way, render nothing.

    @see \App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard
    @see docs/plans/repurchase-cycle-visual-2026-09-13.md
--}}
@php
    use App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard;
@endphp

@if($card !== null)
    @php
        $ringRadius = 30;
        $ringCirc = 2 * M_PI * $ringRadius;
        $ringDash = round($ringCirc * $card->ringFraction(), 2);

        $accent = match (true) {
            $card->state === RepurchaseCycleCard::STATE_SUSPENDED => ['ring' => '#b91c1c', 'chip' => 'bg-red-50 text-red-800 border-red-200'],
            $card->state === RepurchaseCycleCard::STATE_COMPLETED => ['ring' => '#166534', 'chip' => 'bg-green-50 text-green-800 border-green-200'],
            $card->urgent() => ['ring' => '#b91c1c', 'chip' => 'bg-red-50 text-red-800 border-red-200'],
            default => ['ring' => '#166534', 'chip' => 'bg-green-50 text-green-800 border-green-200'],
        };

        // The ring already reads "N days left"; a chip repeating it is wasted
        // width. These three states are the ones a ring cannot express.
        $statusChip = match ($card->state) {
            RepurchaseCycleCard::STATE_COMPLETED => ['text' => 'Completed for this cycle', 'class' => $accent['chip']],
            RepurchaseCycleCard::STATE_SUSPENDED => ['text' => 'Window missed', 'class' => $accent['chip']],
            RepurchaseCycleCard::STATE_NOT_QUALIFIED => ['text' => 'Not started yet', 'class' => 'border-gray-200 bg-white text-gray-600'],
            default => null,
        };
    @endphp

    {{-- No outer margin: the card sits in the hero's grid cell, which owns
         the spacing around it. --}}
    <section class="rounded-2xl border-2 border-brand-200 bg-gradient-to-br from-brand-50 to-white p-4 sm:p-5 shadow-sm"
             aria-labelledby="repurchase-heading">

        <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
            <h2 id="repurchase-heading" class="text-base font-bold text-gray-900 flex items-center gap-2">
                <x-lucide-repeat class="w-5 h-5 text-brand-700" />
                Repurchase cycle
            </h2>
            @if($statusChip !== null)
                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold {{ $statusChip['class'] }}">
                    {{ $statusChip['text'] }}
                </span>
            @endif
        </div>

        @if(! $card->qualified())
            {{-- Nothing is owed yet. One sentence: the gate that opens the
                 cycle and how far along they are — a distributor who sees an
                 empty window cannot tell whether they are safe or unseen. --}}
            <div class="flex items-center gap-4">
                <svg viewBox="0 0 72 72" class="w-20 h-20 shrink-0" role="img"
                     aria-label="Personal BV {{ round($card->qualifyFraction() * 100) }} percent of the amount needed to start a repurchase cycle">
                    <circle cx="36" cy="36" r="{{ $ringRadius }}" fill="none" stroke="#e5e7eb" stroke-width="7"/>
                    <circle cx="36" cy="36" r="{{ $ringRadius }}" fill="none" stroke="#b76017" stroke-width="7"
                            stroke-linecap="round"
                            stroke-dasharray="{{ round($ringCirc * $card->qualifyFraction(), 2) }} {{ round($ringCirc, 2) }}"
                            transform="rotate(-90 36 36)"/>
                    <text x="36" y="41" text-anchor="middle" font-size="15" font-weight="700" fill="#b76017">
                        {{ round($card->qualifyFraction() * 100) }}%
                    </text>
                </svg>

                <p class="flex-1 min-w-0 text-sm text-gray-700">
                    Your cycle starts once your personal purchases reach
                    <strong class="text-gray-900">@bv($card->qualifyBvPaise)</strong> — you have
                    <strong class="text-gray-900">@bv($card->personalBvPaise)</strong>@if($card->qualifyRemainingPaise() > 0), <span class="text-gray-600">@bv($card->qualifyRemainingPaise()) to go</span>@endif.
                    Nothing is due until then.
                </p>
            </div>
        @else
            <div class="flex items-center gap-4">
                <svg viewBox="0 0 72 72" class="w-20 h-20 shrink-0" role="img"
                     aria-label="{{ $card->daysLeft }} of {{ $card->daysTotal }} days remaining in this repurchase cycle">
                    <circle cx="36" cy="36" r="{{ $ringRadius }}" fill="none" stroke="#e5e7eb" stroke-width="7"/>
                    <circle cx="36" cy="36" r="{{ $ringRadius }}" fill="none" stroke="{{ $accent['ring'] }}" stroke-width="7"
                            stroke-linecap="round"
                            stroke-dasharray="{{ $ringDash }} {{ round($ringCirc, 2) }}"
                            transform="rotate(-90 36 36)"/>
                    <text x="36" y="36" text-anchor="middle" font-size="17" font-weight="700" fill="{{ $accent['ring'] }}">
                        {{ $card->daysLeft }}
                    </text>
                    <text x="36" y="49" text-anchor="middle" font-size="8" fill="#6b7280">
                        {{ \Illuminate\Support\Str::plural('day', $card->daysLeft) }} left
                    </text>
                </svg>

                <div class="flex-1 min-w-0 space-y-2">
                    {{-- Dates plus the window length on one line. The anchor day
                         is day 0 and due_date is inclusive, so the span reads as
                         off-by-one unless the card says the last day counts —
                         daysTotal counts both ends, so minus one is the DB-driven
                         comp.repurchase.cycle_days length, never a hardcoded 30. --}}
                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm font-semibold text-gray-900">
                        {{ $card->startDate?->format('d M') }}
                        <span class="text-gray-400">&rarr;</span>
                        {{ $card->endDate?->format('d M Y') }}
                        <span class="text-xs font-normal text-gray-500">{{ $card->daysTotal - 1 }}-day window, last day counts</span>
                    </p>

                    {{-- The two conditions the engine actually checks at window end. --}}
                    <div>
                        <div class="flex items-center justify-between gap-2 text-xs mb-1">
                            <span class="font-medium text-gray-700">
                                Repurchase BV
                                @if($card->bvMet())
                                    <span class="text-green-700">— met</span>
                                @else
                                    <span class="text-gray-600">— @bv($card->bvRemainingPaise()) to go</span>
                                @endif
                            </span>
                            <span class="tabular-nums text-gray-600">@bv($card->completedBvPaise) / @bv($card->requiredBvPaise)</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-gray-200 overflow-hidden">
                            <div class="h-full rounded-full {{ $card->bvMet() ? 'bg-green-600' : 'bg-brand-600' }}"
                                 style="width: {{ round($card->bvFraction() * 100, 1) }}%"></div>
                        </div>
                    </div>

                    <p class="flex items-start gap-2 text-xs text-gray-800">
                        @if($card->walletZeroed)
                            <x-lucide-circle-check class="w-4 h-4 text-green-700 mt-px shrink-0" />
                        @else
                            <x-lucide-circle-dashed class="w-4 h-4 text-amber-600 mt-px shrink-0" />
                        @endif
                        <span>
                            Repurchase wallet at ₹0 on the last day
                            @unless($card->walletZeroed)
                                <span class="text-gray-600">— now ₹{{ \App\Modules\Shared\Support\IndianNumber::format($card->walletBalancePaise / 100, 2) }}</span>
                            @endunless
                        </span>
                    </p>
                </div>
            </div>

            @if($card->state === RepurchaseCycleCard::STATE_SUSPENDED && $card->failureLabel() !== null)
                <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                    {{ $card->failureLabel() }}
                </p>
            @endif
        @endif
    </section>
@endif
