<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    seedCompensationPlanTables();
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
});

/** A distributor who crossed the 600-BV anchor on $date, so a cycle opens. */
function distributorWithAnchor(string $date, int $bvPaise = 60_000): Distributor
{
    $dist = Distributor::factory()->create();

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'O'.uniqid('', true),
        'customer_id' => 1,
        'attributed_distributor_id' => $dist->id,
        'self_consumption' => true,
        'idempotency_key' => 'k'.uniqid('', true),
        'created_at' => $date,
        'updated_at' => $date,
    ]);

    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => BvLedgerEntry::TYPE_ACCRUAL,
        'effective_at' => $date,
    ]);

    return $dist;
}

/** The summary the run wrote onto its own engine_runs row. */
function lastEvaluateRun(): EngineRun
{
    return EngineRun::where('engine_key', 'repurchase.evaluate')->latest('id')->firstOrFail();
}

it('writes the run counts to the engine run summary', function (): void {
    // F24: the one engine that gates the daily cut-off used to leave
    // `summary` NULL, so the Engine Runs page and the health digest had
    // nothing to show for it.
    distributorWithAnchor('2026-07-07');
    distributorWithAnchor('2026-07-13');

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-20']))->toBe(0);

    $summary = lastEvaluateRun()->summary;

    expect($summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_COMPLETED)
        ->and($summary['as_of'])->toBe('2026-07-20')
        ->and($summary['evaluated'])->toBe(2)
        // Both windows are still open on 20 Jul: no verdict taken either way.
        ->and($summary['fulfilled'])->toBe(0)
        ->and($summary['forfeited'])->toBe(0)
        ->and($summary['withheld'])->toBe(0)
        ->and($summary['failed'])->toBe(0)
        ->and($summary['failed_adns'])->toBe([]);
});

it('counts the verdict it takes when a window closes fulfilled', function (): void {
    // The anchor purchase itself meets the obligation, so the 7 Jul window
    // closes fulfilled on 6 Aug and the next one opens the day after.
    $dist = distributorWithAnchor('2026-07-07');

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-08-10']))->toBe(0);

    $summary = lastEvaluateRun()->summary;

    expect($summary['fulfilled'])->toBe(1)
        ->and($summary['forfeited'])->toBe(0)
        ->and($summary['withheld'])->toBe(0);

    expect(RepurchaseCycle::where('distributor_id', $dist->id)
        ->where('status', RepurchaseCycle::STATUS_COMPLETED)->exists())->toBeTrue();
});

it('counts a lapsed window as forfeited and the distributor as withheld', function (): void {
    // Window one is met by the anchor purchase; window two (7 Aug – 6 Sep) has
    // no purchase in it at all, so it closes as a BV shortfall.
    distributorWithAnchor('2026-07-07');

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-09-10']))->toBe(0);

    $summary = lastEvaluateRun()->summary;

    expect($summary['fulfilled'])->toBe(1)
        ->and($summary['forfeited'])->toBe(1)
        ->and($summary['withheld'])->toBe(1);
});

it('carries on past a throwing distributor, evaluates the rest, and exits 0', function (): void {
    // E5, client decision 2026-09-27: one distributor's data problem costs
    // that distributor tonight, never the other N.
    $first = distributorWithAnchor('2026-07-07');
    $broken = distributorWithAnchor('2026-07-13');
    $last = distributorWithAnchor('2026-07-24');

    RepurchaseCycle::creating(function (RepurchaseCycle $cycle) use ($broken): void {
        if ((int) $cycle->distributor_id === $broken->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(0);

    expect(RepurchaseCycle::where('distributor_id', $first->id)->exists())->toBeTrue()
        ->and(RepurchaseCycle::where('distributor_id', $broken->id)->exists())->toBeFalse()
        ->and(RepurchaseCycle::where('distributor_id', $last->id)->exists())->toBeTrue();
});

it('records a completed_with_skips summary naming the skipped ADNs, ids and exception class', function (): void {
    distributorWithAnchor('2026-07-07');
    $broken = distributorWithAnchor('2026-07-13');

    RepurchaseCycle::creating(function (RepurchaseCycle $cycle) use ($broken): void {
        if ((int) $cycle->distributor_id === $broken->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(0);

    $run = lastEvaluateRun();

    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED)
        ->and($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_COMPLETED_WITH_SKIPS)
        ->and($run->summary['evaluated'])->toBe(1)
        ->and($run->summary['failed'])->toBe(1)
        ->and($run->summary['failed_adns'])->toBe([$broken->adn])
        ->and($run->summary['failed_distributor_ids'])->toBe([$broken->id])
        // The class, never the message: an exception message can carry
        // anything the data put in it.
        ->and($run->summary['failure_classes'])->toBe([RuntimeException::class])
        ->and($run->summary['reason'])->toContain('skipped');
});

it('caps skips at 1% of the roster it looked at, never below 10', function (): void {
    expect(RepurchaseEvaluateCommand::effectiveSkipCap(300))->toBe(10)
        ->and(RepurchaseEvaluateCommand::effectiveSkipCap(5_000))->toBe(50)
        ->and(RepurchaseEvaluateCommand::effectiveSkipCap(1_000_000))->toBe(500);   // configured 500 wins
});

it('fails closed above the skip cap, because that is a fault in the run and not in the data', function (): void {
    // Eleven failures on an eleven-strong roster: over the floor of ten. Two
    // exception classes, so it is the cap that trips, not the single-class rule.
    for ($i = 0; $i < 11; $i++) {
        distributorWithAnchor('2026-07-0'.(($i % 9) + 1));
    }

    $n = 0;
    RepurchaseCycle::creating(function () use (&$n): void {
        throw ($n++ % 2 === 0)
            ? new RuntimeException('simulated failure')
            : new LogicException('simulated failure');
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1);

    $run = lastEvaluateRun();

    expect($run->status)->toBe(EngineRun::STATUS_FAILED)
        ->and($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_FAILED_PARTIAL)
        ->and($run->summary['failed'])->toBe(11)
        ->and($run->summary['skip_cap'])->toBe(10)
        ->and($run->summary['reason'])->toContain('more than the 10');
});

it('fails closed when ten or more failures share one exception class', function (): void {
    config(['arovolife.compensation.evaluate_skip_cap' => 500]);
    for ($i = 0; $i < 10; $i++) {
        distributorWithAnchor('2026-07-0'.(($i % 9) + 1));
    }

    RepurchaseCycle::creating(function (): void {
        throw new RuntimeException('simulated systemic failure');
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1);
    $run = lastEvaluateRun();
    expect($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_FAILED_PARTIAL)
        ->and($run->summary['reason'])->toContain('same class');
});

it('refuses while another evaluation run is in flight', function (): void {
    Carbon::setTestNow('2026-07-30 00:05:00');
    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-07-30',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-07-30 00:02:00'),
    ]);
    distributorWithAnchor('2026-07-07');

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1)
        ->and(Artisan::output())->toContain('still in flight')
        ->and(RepurchaseCycle::count())->toBe(0);
});
