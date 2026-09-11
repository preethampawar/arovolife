<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('carries on past a throwing distributor and evaluates the rest', function (): void {
    // F23: one distributor's data problem must not cost the other N their
    // evaluation. Before this, the loop caught the exception but every
    // distributor after it was still evaluated — what changed is that the run
    // now says WHO failed, rather than leaving it in the log.
    $first = distributorWithAnchor('2026-07-07');
    $broken = distributorWithAnchor('2026-07-13');
    $last = distributorWithAnchor('2026-07-24');

    RepurchaseCycle::creating(function (RepurchaseCycle $cycle) use ($broken): void {
        if ((int) $cycle->distributor_id === $broken->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1);

    expect(RepurchaseCycle::where('distributor_id', $first->id)->exists())->toBeTrue()
        ->and(RepurchaseCycle::where('distributor_id', $broken->id)->exists())->toBeFalse()
        ->and(RepurchaseCycle::where('distributor_id', $last->id)->exists())->toBeTrue();
});

it('records a failed_partial summary naming the failed ADNs and the exception class', function (): void {
    distributorWithAnchor('2026-07-07');
    $broken = distributorWithAnchor('2026-07-13');

    RepurchaseCycle::creating(function (RepurchaseCycle $cycle) use ($broken): void {
        if ((int) $cycle->distributor_id === $broken->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    expect(Artisan::call('repurchase:evaluate', ['--date' => '2026-07-30']))->toBe(1);

    $run = lastEvaluateRun();

    expect($run->status)->toBe(EngineRun::STATUS_FAILED)
        ->and($run->summary['outcome'])->toBe(RepurchaseEvaluateCommand::OUTCOME_FAILED_PARTIAL)
        ->and($run->summary['evaluated'])->toBe(1)
        ->and($run->summary['failed'])->toBe(1)
        ->and($run->summary['failed_adns'])->toBe([$broken->adn])
        // The class, never the message: an exception message can carry
        // anything the data put in it.
        ->and($run->summary['failure_classes'])->toBe([RuntimeException::class]);
});
