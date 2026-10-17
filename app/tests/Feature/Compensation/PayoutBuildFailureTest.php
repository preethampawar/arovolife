<?php

declare(strict_types=1);

/**
 * Skip-and-continue for payout batches (user 2026-10-17): one distributor
 * whose line cannot be built must not block the batch. They are skipped, the
 * error is kept in full, and an admin retries them from Payouts → Build
 * failures — into the same batch before approval, or the next batch pays them.
 */

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Jobs\RetryPayoutBuildFailureJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutBuildFailure;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutBuildFailureRecorder;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\Recompute\WindowedStateWiper;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    DB::table('settings')->updateOrInsert(
        ['key' => 'payout.gateway'],
        ['value' => 'manual_neft', 'version' => 1, 'updated_at' => now()],
    );
    $this->failing = new stdClass;
    $this->failing->ids = [];
    $this->failing->exception = fn (): Throwable => new RuntimeException('simulated build failure');
    $failing = $this->failing;

    // Any line for a distributor in the failing set throws while it is written,
    // inside the per-distributor transaction — the real failure shape.
    PayoutLineItem::creating(function (PayoutLineItem $line) use ($failing): void {
        if (in_array((int) $line->distributor_id, $failing->ids, true)) {
            throw ($failing->exception)();
        }
    });
});

function skipTestDistributor(int $grossPaise = 100_000, ?Carbon $earnedOn = null, string $type = 'gsb_credit'): Distributor
{
    $dist = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 800_000 + $dist->id,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    app(WalletService::class)->credit(
        $dist->id, $grossPaise, $type, walletRef(), 'test_reference',
        earnedOn: $type === 'gsb_credit' ? ($earnedOn ?? PayoutBatch::weeklyEarningWindow(Carbon::today())['end']) : null,
    );

    return $dist;
}

function retryFailure(PayoutBuildFailure $failure, ?int $actorId = null): void
{
    expect(app(PayoutBuildFailureRecorder::class)->claimForRetry($failure, $actorId))->toBeTrue();
    RetryPayoutBuildFailureJob::dispatchSync((int) $failure->id, $actorId);
}

it('skips a failing distributor, keeps the batch approvable and records the full error', function (): void {
    $ok = skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and($batch->distributor_count)->toBe(1)
        ->and(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $ok->id)->exists())->toBeTrue()
        ->and(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $bad->id)->exists())->toBeFalse()
        // Nothing debited: the skipped distributor's money is still in the wallet.
        ->and(app(WalletService::class)->balancePaise($bad->id))->toBe(100_000);

    $failure = PayoutBuildFailure::sole();
    expect($failure->payout_batch_id)->toBe($batch->id)
        ->and($failure->distributor_id)->toBe($bad->id)
        ->and($failure->status)->toBe(PayoutBuildFailure::STATUS_OPEN)
        ->and($failure->exception_class)->toBe(RuntimeException::class)
        ->and($failure->message)->toBe('simulated build failure')
        ->and($failure->error_detail)->toContain('RuntimeException: simulated build failure')
        ->and($failure->error_detail)->toContain('#0 ')
        ->and(AuditLog::where('action', 'payout.build_failure.recorded')->count())->toBe(1);

    $finalised = AuditLog::where('action', 'payout.batch.finalised')->latest('id')->first();
    expect($finalised->details['skipped_distributor_count'])->toBe(1)
        ->and($finalised->details['systemic_failure'])->toBeFalse();
});

it('treats ten failures of one kind as a fault in the run and blocks the batch until retried', function (): void {
    skipTestDistributor();
    $bad = collect(range(1, 10))->map(fn () => skipTestDistributor());
    $this->failing->ids = $bad->pluck('id')->map(fn ($id) => (int) $id)->all();

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    expect($batch->status)->toBe(PayoutBatch::STATUS_PARTIALLY_FAILED)
        ->and(PayoutBuildFailure::count())->toBe(10);

    $this->failing->ids = [];
    PayoutBuildFailure::query()->orderBy('id')->get()->each(fn (PayoutBuildFailure $f) => retryFailure($f));

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and($batch->fresh()->distributor_count)->toBe(11);
});

it('nine failures of different kinds in a small batch do not block it', function (): void {
    expect(PayoutService::isSystemicFailure(['A' => 5, 'B' => 4], 20))->toBeFalse()
        ->and(PayoutService::isSystemicFailure(['A' => 11], 2_000))->toBeTrue()
        ->and(PayoutService::isSystemicFailure(['A' => 6, 'B' => 6], 900))->toBeTrue()
        ->and(PayoutService::isSystemicFailure(['A' => 6, 'B' => 6], 5_000))->toBeFalse();
});

it('a retry after the fix builds the line into the same batch and resolves the failure', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $netBefore = $batch->total_net_paise;

    $this->failing->ids = [];
    $actor = User::factory()->create();
    retryFailure(PayoutBuildFailure::sole(), $actor->id);

    $batch->refresh();
    $failure = PayoutBuildFailure::sole();

    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $bad->id)->count())->toBe(1)
        ->and($batch->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and($batch->distributor_count)->toBe(2)
        ->and($batch->total_net_paise)->toBe($netBefore * 2)
        ->and($failure->status)->toBe(PayoutBuildFailure::STATUS_RESOLVED)
        ->and($failure->resolution)->toBe(PayoutBuildFailure::RESOLUTION_BUILT)
        ->and($failure->resolved_by_batch_id)->toBe($batch->id)
        ->and(app(WalletService::class)->balancePaise($bad->id))->toBe(0)
        // The retrying admin is the batch's maker now, so cannot approve it.
        ->and($batch->created_by)->toBe($actor->id)
        ->and(AuditLog::where('action', 'payout.build_failure.retry_requested')->where('actor_id', $actor->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'payout.build_failure.resolved')->where('actor_id', $actor->id)->count())->toBe(1);
});

it('un-building the batch that skipped a distributor removes its failure rows', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    $svc->unbuildBatch($batch, 42, 'Rebuilding the week');

    expect(PayoutBuildFailure::count())->toBe(0);
});

it('a retry that fails again keeps the row open with the new error and one more attempt', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $this->failing->exception = fn (): Throwable => new LogicException('still broken');
    retryFailure(PayoutBuildFailure::sole());

    $failure = PayoutBuildFailure::sole();
    expect($failure->status)->toBe(PayoutBuildFailure::STATUS_OPEN)
        ->and($failure->attempts)->toBe(2)
        ->and($failure->exception_class)->toBe(LogicException::class)
        ->and($failure->message)->toBe('still broken');
});

it('approval refuses while skipped distributors are not accepted, and accepts only the count the approver saw', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());
    $approver = User::factory()->create();

    expect($svc->approve($batch, $approver->id)->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and($svc->approve($batch->fresh(), $approver->id, 1)->status)->toBe(PayoutBatch::STATUS_APPROVED);

    $approved = AuditLog::where('action', 'payout.batch.approved')->sole();
    expect($approved->details['approved_without_skipped_distributors'])->toBe(1);
});

it('a batch approved without a distributor is never appended to; the next batch pays them and closes the row', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $svc = app(PayoutService::class);
    $week1 = $svc->runWeeklyBatch(Carbon::today());
    $svc->approve($week1, User::factory()->create()->id, 1);

    $this->failing->ids = [];
    retryFailure(PayoutBuildFailure::sole());

    $failure = PayoutBuildFailure::sole();
    expect(PayoutLineItem::where('payout_batch_id', $week1->id)->where('distributor_id', $bad->id)->exists())->toBeFalse()
        ->and($failure->status)->toBe(PayoutBuildFailure::STATUS_OPEN)
        ->and($failure->message)->toContain('approved without this distributor');

    $week2 = $svc->runWeeklyBatch(Carbon::today()->addWeek());
    $line = PayoutLineItem::where('payout_batch_id', $week2->id)->where('distributor_id', $bad->id)->sole();
    $failure->refresh();

    expect($line->gross_paise)->toBe(100_000)
        ->and($failure->status)->toBe(PayoutBuildFailure::STATUS_RESOLVED)
        ->and($failure->resolution)->toBe(PayoutBuildFailure::RESOLUTION_PAID_BY_LATER_BATCH)
        ->and($failure->resolved_by_batch_id)->toBe($week2->id);
});

it('un-building the batch that paid a skipped distributor re-opens their failure', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $svc = app(PayoutService::class);
    $week1 = $svc->runWeeklyBatch(Carbon::today());
    $svc->approve($week1, User::factory()->create()->id, 1);

    $this->failing->ids = [];
    $week2 = $svc->runWeeklyBatch(Carbon::today()->addWeek());
    expect(PayoutBuildFailure::sole()->status)->toBe(PayoutBuildFailure::STATUS_RESOLVED);

    $summary = $svc->unbuildBatch($week2, 42, 'Rebuilding the week');

    $failure = PayoutBuildFailure::sole();
    expect($summary['build_failures_reopened'])->toBe(1)
        ->and($failure->status)->toBe(PayoutBuildFailure::STATUS_OPEN)
        ->and($failure->resolved_by_batch_id)->toBeNull()
        ->and($failure->payout_batch_id)->toBe($week1->id);
});

it('a held line in the later batch does not count as paying the skipped distributor', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $svc = app(PayoutService::class);
    $week1 = $svc->runWeeklyBatch(Carbon::today());
    $svc->approve($week1, User::factory()->create()->id, 1);

    // No bank account on file: the later batch holds them instead of paying.
    $this->failing->ids = [];
    DB::table('distributors')->where('id', $bad->id)->update(['bank_account_enc' => null]);
    $week2 = $svc->runWeeklyBatch(Carbon::today()->addWeek());

    expect(PayoutLineItem::where('payout_batch_id', $week2->id)->where('distributor_id', $bad->id)->value('status'))
        ->toBe(PayoutLineItem::STATUS_NO_BANK_ACCOUNT)
        ->and(PayoutBuildFailure::sole()->status)->toBe(PayoutBuildFailure::STATUS_OPEN);
});

it('stores the error without PAN, account numbers, IFSC or function arguments', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $this->failing->exception = fn (): Throwable => new RuntimeException('Bank rejected ABCDE1234F acct 123456789012345 IFSC SBIN0001234');

    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $failure = PayoutBuildFailure::sole();
    foreach ([$failure->message, $failure->error_detail] as $text) {
        expect($text)->not->toContain('ABCDE1234F')
            ->and($text)->not->toContain('123456789012345')
            ->and($text)->not->toContain('SBIN0001234')
            ->and($text)->toContain('[REDACTED:PAN]');
    }

    $rendered = PayoutBuildFailureRecorder::render((function (string $secret): Throwable {
        return new RuntimeException('boom');
    })('ARG-SECRET-VALUE'));
    expect($rendered)->not->toContain('ARG-SECRET-VALUE');
});

it('skips and retries a monthly distributor into the same monthly batch', function (): void {
    skipTestDistributor(type: 'gbb_credit');
    $bad = skipTestDistributor(type: 'gbb_credit');
    $this->failing->ids = [$bad->id];
    $svc = app(PayoutService::class);
    $batch = $svc->runMonthlyBatch(Carbon::today());

    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and(PayoutBuildFailure::sole()->payout_batch_id)->toBe($batch->id);

    $this->failing->ids = [];
    retryFailure(PayoutBuildFailure::sole());

    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $bad->id)->exists())->toBeTrue()
        ->and(PayoutBuildFailure::sole()->resolution)->toBe(PayoutBuildFailure::RESOLUTION_BUILT);
});

it('a recompute wipe removes the failure rows of the wiped batches', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    app(WindowedStateWiper::class)->wipe(Carbon::today()->subMonths(2));

    expect(PayoutBuildFailure::count())->toBe(0);
});

// ── The page ──────────────────────────────────────────────────────────

it('lists open failures with the full error, and Retry queues one job per click', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $failure = PayoutBuildFailure::sole();

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    $this->actingAs($finance)
        ->get(route('admin.compensation.payout-failures.index'))
        ->assertOk()
        ->assertSee($bad->adn)
        ->assertSee('Full error log')
        ->assertSee('RuntimeException: simulated build failure');

    Queue::fake();
    $this->actingAs($finance)->post(route('admin.compensation.payout-failures.retry', $failure))->assertSessionHas('success');
    $this->actingAs($finance)->post(route('admin.compensation.payout-failures.retry', $failure))->assertSessionHas('error');

    Queue::assertPushed(RetryPayoutBuildFailureJob::class, 1);
    expect($failure->fresh()->status)->toBe(PayoutBuildFailure::STATUS_RETRYING);
});

it('the page and retry need finance.record', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $compliance = User::factory()->create(['status' => 'active']);
    $compliance->assignRole('admin-compliance');

    $this->actingAs($compliance)->get(route('admin.compensation.payout-failures.index'))->assertForbidden();
});

it('the batch page refuses an approval that did not tick past the skipped distributors', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $approver = User::factory()->create(['status' => 'active']);
    $approver->assignRole('admin');

    $this->actingAs($approver)
        ->get(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertOk()
        ->assertSee('could not be built into this batch')
        ->assertSee('Approve without the 1 distributor(s)');

    $this->actingAs($approver)
        ->post(route('admin.compensation.weekly-payouts.approve', $batch))
        ->assertSessionHas('error');
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PENDING);

    $this->actingAs($approver)
        ->post(route('admin.compensation.weekly-payouts.approve', $batch), ['approve_without_skipped' => '1', 'skipped_count' => '1'])
        ->assertSessionHas('success');
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED);
});

it('the admin whose retry built a distributor into a batch cannot approve it, even when the batch has another maker', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $batch->update(['created_by' => User::factory()->create()->id]);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $this->failing->ids = [];
    retryFailure(PayoutBuildFailure::sole(), $admin->id);

    $this->actingAs($admin)
        ->post(route('admin.compensation.weekly-payouts.approve', $batch))
        ->assertSessionHas('error');

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and(AuditLog::where('action', 'payout.batch.self_approval_refused')->sole()->details['retried_distributors_into_batch'])->toBeTrue();
});

it('a retry job whose stale claim another admin took over does not build the line', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $this->failing->ids = [];

    $first = User::factory()->create();
    $second = User::factory()->create();
    $failure = PayoutBuildFailure::sole();
    $recorder = app(PayoutBuildFailureRecorder::class);

    expect($recorder->claimForRetry($failure, $first->id))->toBeTrue();
    $failure->update(['retry_requested_at' => now()->subMinutes(PayoutBuildFailureRecorder::STALE_RETRY_MINUTES + 1)]);
    expect($recorder->claimForRetry($failure->fresh(), $second->id))->toBeTrue();

    // The first admin's job finally runs: the claim is no longer theirs.
    expect(app(PayoutService::class)->retryBuildFailure((int) $failure->id, $first->id))->toBe('not_claimed')
        ->and(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $bad->id)->exists())->toBeFalse()
        ->and($failure->fresh()->status)->toBe(PayoutBuildFailure::STATUS_RETRYING);

    // The second admin's job builds it, and the row names them.
    expect(app(PayoutService::class)->retryBuildFailure((int) $failure->id, $second->id))->toBe('built')
        ->and((int) $failure->fresh()->retry_requested_by)->toBe($second->id);
});

it('approval re-checks maker-checker under the sweep lock, after a retry that finished since the page loaded', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $other = skipTestDistributor();
    $this->failing->ids = [$bad->id, $other->id];
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $batch->update(['created_by' => User::factory()->create()->id]);
    $this->failing->ids = [$other->id];

    // The controller's check passed while the retry was still queued; the
    // retry built the line before the approval took the lock.
    $approver = User::factory()->create();
    retryFailure(PayoutBuildFailure::where('distributor_id', $bad->id)->sole(), $approver->id);

    $result = app(PayoutService::class)->approve($batch->fresh(), $approver->id, 1);

    expect($result->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and($batch->fresh()->approved_at)->toBeNull()
        ->and(AuditLog::where('action', 'payout.batch.self_approval_refused')->sole()->details['checked_under_sweep_lock'])->toBeTrue();
});

it('a stale claim taken over while the build runs still names the admin whose job built the line', function (): void {
    skipTestDistributor();
    $bad = skipTestDistributor();
    $this->failing->ids = [$bad->id];
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $batch->update(['created_by' => User::factory()->create()->id]);
    $this->failing->ids = [];

    $builder = User::factory()->create();
    $takeover = User::factory()->create();
    $failure = PayoutBuildFailure::sole();
    expect(app(PayoutBuildFailureRecorder::class)->claimForRetry($failure, $builder->id))->toBeTrue();

    // Another admin re-claims the (stale) row while the builder's job is
    // writing the line — outside the sweep lock the build holds.
    PayoutLineItem::creating(function (PayoutLineItem $line) use ($bad, $failure, $takeover): void {
        if ((int) $line->distributor_id === $bad->id) {
            PayoutBuildFailure::whereKey($failure->id)->update(['retry_requested_by' => $takeover->id]);
        }
    });

    expect(app(PayoutService::class)->retryBuildFailure((int) $failure->id, $builder->id))->toBe('built')
        ->and((int) $failure->fresh()->retry_requested_by)->toBe($builder->id)
        ->and(app(PayoutService::class)->approverIsMaker($batch->fresh(), $builder->id))->toBeTrue()
        ->and(app(PayoutService::class)->approverIsMaker($batch->fresh(), $takeover->id))->toBeFalse();
});
