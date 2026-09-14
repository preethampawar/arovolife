{{-- Simulated compensation figures are standing in this database.

     Rendered only on a dev/staging environment whose recompute gate is open AND
     that has been left on a projection, so production never reaches the query
     behind it (RecomputeState returns before touching the cache when the guard
     is shut). Every figure dated after the "as at" below was computed on a clock
     that has not arrived — a projection, not a fact — and hard rule 3 does not
     soften on staging. --}}
@php
    // Gated on isProjected(), NOT on the date: a projected run whose audit row
    // carries no `simulated_through` detail would otherwise pause the scheduler
    // while showing nothing at all — simulated in silence, which is the one
    // outcome this partial exists to prevent.
    $recomputeState = app(\App\Modules\Compensation\Services\Recompute\RecomputeState::class);
    $isProjected = $recomputeState->isProjected();
    $projectedThrough = $isProjected ? $recomputeState->projectedThrough() : null;
    $projectedAt = $isProjected ? $recomputeState->projectedAt() : null;
@endphp
@if($isProjected)
    <div class="bg-amber-500 text-amber-950" role="status">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2 text-sm">
            <p class="font-medium flex flex-wrap items-center gap-x-1.5 gap-y-1">
                <span class="inline-flex items-center gap-1.5 font-bold">
                    <x-lucide-flask-conical class="w-4 h-4 shrink-0" />
                    Test environment — projected figures
                </span>
                <span>
                    @if($projectedThrough !== null)
                        Compensation has been simulated through
                        <strong>{{ $projectedThrough->format('d M Y') }}</strong>
                    @else
                        Compensation here is holding simulated figures
                    @endif
                    @if($projectedAt !== null)
                        as at {{ $projectedAt->format('d M Y H:i') }}
                    @endif
                    on the orders that existed then. Bonuses, repurchase cycles, wallet balances and payouts dated
                    after that moment are provisional. The scheduled engines are paused here until the nightly reset
                    (23:30 IST) or an “up to now” recompute.
                </span>
            </p>
        </div>
    </div>
@endif
