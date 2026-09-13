{{--
    Repurchase cycle status card.

    Answers one question at a glance: where am I in my repurchase window, and
    what is still outstanding? Four states, because they are four different
    answers — not qualified yet, running, met, and missed — and blurring them
    is what makes a status card useless.

    The ring geometry matches resources/views/dashboard/_cooling-off.blade.php
    so the platform has one visual language for "time remaining".

    Expects: $card (RepurchaseCycleCard|null). Null means the engine flag is
    off or the income tables are unreadable — either way, render nothing.

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
    @endphp

    <section class="mb-8 rounded-2xl border-2 border-brand-200 bg-gradient-to-br from-brand-50 to-white p-5 shadow-sm"
             aria-labelledby="repurchase-heading">

        <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
            <h2 id="repurchase-heading" class="text-base font-bold text-gray-900 flex items-center gap-2">
                <x-lucide-repeat class="w-5 h-5 text-brand-700" />
                Repurchase cycle
            </h2>
            @if($card->qualified())
                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold {{ $accent['chip'] }}">
                    {{ match($card->state) {
                        RepurchaseCycleCard::STATE_COMPLETED => 'Completed for this cycle',
                        RepurchaseCycleCard::STATE_SUSPENDED => 'Window missed',
                        default => $card->daysLeft . ' ' . \Illuminate\Support\Str::plural('day', $card->daysLeft) . ' left',
                    } }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-600">
                    Not started yet
                </span>
            @endif
        </div>

        @if(! $card->qualified())
            {{-- Nothing is owed yet. Show the one gate that opens the cycle,
                 and how far along they are — a distributor who sees an empty
                 window cannot tell whether they are safe or simply unseen. --}}
            <div class="flex items-start gap-5 flex-wrap">
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

                <div class="flex-1 min-w-64">
                    <p class="text-sm text-gray-700 mb-3">
                        Your repurchase cycle starts once your personal purchases reach
                        <strong>@bv($card->qualifyBvPaise)</strong>. Until then nothing is due.
                    </p>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-start gap-2">
                            @if($card->qualifyFraction() >= 1)
                                <x-lucide-circle-check class="w-4 h-4 text-green-700 mt-0.5 shrink-0" />
                            @else
                                <x-lucide-circle-dashed class="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
                            @endif
                            <span class="text-gray-800">
                                Personal purchases of @bv($card->qualifyBvPaise)
                                <span class="text-gray-600">
                                    — you have @bv($card->personalBvPaise)
                                    @if($card->qualifyRemainingPaise() > 0)
                                        (@bv($card->qualifyRemainingPaise()) to go)
                                    @endif
                                </span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        @else
            <div class="flex items-start gap-5 flex-wrap">
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

                <div class="flex-1 min-w-64 space-y-3">
                    <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Cycle starts</dt>
                            <dd class="font-semibold text-gray-900">{{ $card->startDate?->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Cycle ends</dt>
                            <dd class="font-semibold text-gray-900">{{ $card->endDate?->format('d M Y') }}</dd>
                        </div>
                        <div>
                            {{-- Deliberately the obligation, not the window length:
                                 due_date is inclusive, so a "30-day" cycle spans 31
                                 calendar days and printing that number invites an
                                 argument the distributor cannot win. The two dates
                                 above already say the window exactly. --}}
                            <dt class="text-xs font-medium text-gray-500">Required this cycle</dt>
                            <dd class="font-semibold text-gray-900">@bv($card->requiredBvPaise)</dd>
                        </div>
                    </dl>

                    {{-- The two conditions the engine actually checks at window end. --}}
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
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

                    <ul class="space-y-1.5 text-sm">
                        <li class="flex items-start gap-2">
                            @if($card->walletZeroed)
                                <x-lucide-circle-check class="w-4 h-4 text-green-700 mt-0.5 shrink-0" />
                            @else
                                <x-lucide-circle-dashed class="w-4 h-4 text-amber-600 mt-0.5 shrink-0" />
                            @endif
                            <span class="text-gray-800">
                                Repurchase wallet at ₹0 on the last day
                                @unless($card->walletZeroed)
                                    <span class="text-gray-600">— currently ₹{{ \App\Modules\Shared\Support\IndianNumber::format($card->walletBalancePaise / 100, 2) }}</span>
                                @endunless
                            </span>
                        </li>
                    </ul>

                    @if($card->state === RepurchaseCycleCard::STATE_SUSPENDED && $card->failureLabel() !== null)
                        <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                            {{ $card->failureLabel() }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </section>
@endif
