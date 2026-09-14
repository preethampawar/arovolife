<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    config(['arovolife.recompute.enabled' => true]);
    app(RecomputeState::class)->forget();
});

/** The audit row a recompute writes — the only record of what a run was asked for. */
function recomputeStateAuditRow(string $action, ?string $horizon, ?string $simulatedThrough = null): void
{
    $details = ['note' => 'test'];

    if ($horizon !== null) {
        $details['horizon'] = $horizon;
        $details['simulated_through'] = $simulatedThrough;
    }

    AuditLog::create([
        'actor_id' => null,
        'action' => $action,
        'subject_type' => 'platform',
        'subject_id' => 0,
        'details' => $details,
    ]);

    app(RecomputeState::class)->forget();
}

it('reads a database with no recompute history as production-faithful', function (): void {
    $state = app(RecomputeState::class);

    expect($state->isProjected())->toBeFalse()
        ->and($state->projectedThrough())->toBeNull()
        ->and($state->schedulerEnginesAllowed())->toBeTrue();
});

it('marks the environment projected once a simulated run is asked for', function (): void {
    recomputeStateAuditRow('compensation.recompute_all.queued', 'projection', '2026-10-08 04:00:00');

    $state = app(RecomputeState::class);

    expect($state->isProjected())->toBeTrue()
        ->and($state->projectedThrough()?->toDateTimeString())->toBe('2026-10-08 04:00:00')
        // The scheduled engines would otherwise run tonight against a
        // carry-forward store the projection has already advanced past.
        ->and($state->schedulerEnginesAllowed())->toBeFalse();
});

it('stays projected when the run that asked for it never finished', function (): void {
    // Only the `queued` row exists: the worker died half-way. The simulated rows
    // it wrote are still standing, so the environment must not quietly read as
    // faithful and let the scheduler loose on them.
    recomputeStateAuditRow('compensation.recompute_all.queued', 'today');

    expect(app(RecomputeState::class)->isProjected())->toBeTrue();
});

it('clears the projection when a later run is production-faithful', function (): void {
    recomputeStateAuditRow('compensation.recompute_all', 'projection', '2026-10-08 04:00:00');
    expect(app(RecomputeState::class)->isProjected())->toBeTrue();

    // What the nightly 23:30 reset writes.
    recomputeStateAuditRow('compensation.recompute_all', 'now');

    $state = app(RecomputeState::class);

    expect($state->isProjected())->toBeFalse()
        ->and($state->schedulerEnginesAllowed())->toBeTrue();
});

it('reads a row written before horizons existed as production-faithful', function (): void {
    recomputeStateAuditRow('compensation.recompute_all', null);

    expect(app(RecomputeState::class)->isProjected())->toBeFalse();
});

it('pauses the scheduled engines while a replay is in flight', function (): void {
    // Mid-replay the derived rows are being rewritten underneath any engine that
    // reads them — and this is also what closes the old "never recompute between
    // 00:00 and 00:10 IST" rule (F125).
    app(RecomputeProgress::class)->start();

    expect(app(RecomputeState::class)->schedulerEnginesAllowed())->toBeFalse();
});

it('never pauses production, whatever the audit log says', function (): void {
    recomputeStateAuditRow('compensation.recompute_all', 'projection', '2026-10-08 04:00:00');

    app()->detectEnvironment(fn (): string => 'production');
    app(RecomputeState::class)->forget();

    try {
        $state = app(RecomputeState::class);

        expect($state->schedulerEnginesAllowed())->toBeTrue()
            ->and($state->isProjected())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('holds every compensation engine behind the same pause', function (): void {
    // Every entry that computes, spends or reports a period's economics. A new
    // one added without the filter would run against projected state — and
    // `payout:auto-retry-failed` is the one that reaches a bank, so it is the
    // one that must never be forgotten here.
    $guarded = [
        'repurchase:evaluate',
        'gsb:daily-cutoff',
        'gsb:weekly-payout',
        'compensation:monthly-close',
        'compensation:monthly-payout-close',
        'payout:auto-retry-failed',
        'compensation:engine-health-digest',
    ];

    recomputeStateAuditRow('compensation.recompute_all', 'projection', '2026-10-08 04:00:00');

    $unfiltered = [];

    foreach (Schedule::events() as $event) {
        foreach ($guarded as $signature) {
            if (preg_match("/\bartisan'?\s+".preg_quote($signature, '/').'(\s|$)/', (string) $event->command) !== 1) {
                continue;
            }

            if ($event->filtersPass(app())) {
                $unfiltered[] = $signature;
            }
        }
    }

    expect($unfiltered)->toBe([], 'These would still run against projected figures: '.implode(', ', $unfiltered));
});

/*
|--------------------------------------------------------------------------
| What a projection must not let out of the building
|--------------------------------------------------------------------------
*/

it('marks every report download while the environment holds projected figures', function (): void {
    // A spreadsheet outlives the page it came from, and the banner does not
    // travel with it. Two of these reports are distributor-facing (GSB history,
    // wallet ledger), so an unmarked workbook of next month's bonuses is exactly
    // the artifact hard rule 3 exists to prevent.
    recomputeStateAuditRow('compensation.recompute_all', 'projection', '2026-10-08 04:00:00');

    $response = ReportExport::respond(
        Request::create('/x', 'GET', ['format' => 'csv']),
        'gsb-history-2026-09',
        [['key' => 'date', 'label' => 'Date'], ['key' => 'amount', 'label' => 'Amount']],
        [['date' => '2026-09-14', 'amount' => '100']],
    );

    expect($response->headers->get('Content-Disposition'))
        ->toContain('PROJECTED-2026-10-08-gsb-history-2026-09');

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toContain('PROJECTED FIGURES')
        ->and($body)->toContain('08 Oct 2026')
        ->and($body)->toContain('2026-09-14');
});

it('leaves a report download untouched on a production-faithful environment', function (): void {
    $response = ReportExport::respond(
        Request::create('/x', 'GET', ['format' => 'csv']),
        'gsb-history-2026-09',
        [['key' => 'date', 'label' => 'Date']],
        [['date' => '2026-09-14']],
    );

    expect($response->headers->get('Content-Disposition'))
        ->toContain('gsb-history-2026-09')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('PROJECTED');

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->not->toContain('PROJECTED FIGURES');
});
