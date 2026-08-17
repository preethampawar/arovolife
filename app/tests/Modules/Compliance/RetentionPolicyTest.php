<?php

declare(strict_types=1);

/**
 * The published retention period is measured and, where unambiguous, enforced
 * (R-54; DPDP 2023 §8(7)).
 *
 * Eight years is stated in terms.md §15, privacy.md and the data-model doc,
 * and until now nothing read any of them. A stated period is a ceiling as
 * well as a floor, so holding data indefinitely while telling people it is
 * held for eight years is the discrepancy that matters.
 *
 * RET-01: rows past the window are counted
 * RET-02: rows inside the window are left alone
 * RET-03: the command reports without deleting unless forced
 * RET-04: --force deletes only the purgeable categories
 * RET-05: purging a non-purgeable category is refused, not silently skipped
 * RET-06: categories needing a human decision are surfaced, not hidden
 */

use App\Modules\Compliance\Services\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function retOrientationView(string $startedAt): void
{
    DB::table('orientation_views')->insert([
        'distributor_id' => random_int(1, 100000),
        'video_id' => 'PHASE1_ORIENTATION_V1',
        'started_at' => $startedAt,
        'watch_percent' => 0,
    ]);
}

it('RET-01: counts rows past the retention window', function () {
    retOrientationView(now()->subYears(9)->toDateTimeString());
    retOrientationView(now()->subYears(10)->toDateTimeString());

    $row = collect(app(RetentionPolicy::class)->report())->firstWhere('key', 'orientation_views');

    expect($row['expired'])->toBe(2)
        ->and($row['years'])->toBe(8);
});

it('RET-02: leaves rows inside the window alone', function () {
    // Seven years old — still inside the eight-year period, so it is not
    // merely un-purged, it must not even be counted as due.
    retOrientationView(now()->subYears(7)->toDateTimeString());

    $row = collect(app(RetentionPolicy::class)->report())->firstWhere('key', 'orientation_views');

    expect($row['expired'])->toBe(0);
});

it('RET-03: reports without deleting unless forced', function () {
    retOrientationView(now()->subYears(9)->toDateTimeString());

    // A retention job that deletes on its first accidental invocation is
    // worse than no retention job.
    $this->artisan('compliance:retention-report')
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);

    expect(DB::table('orientation_views')->count())->toBe(1);
});

it('RET-04: --force deletes only the purgeable categories', function () {
    retOrientationView(now()->subYears(9)->toDateTimeString());
    retOrientationView(now()->subYears(2)->toDateTimeString());

    DB::table('audit_log')->insert([
        'actor_id' => null, 'action' => 'ancient.action', 'subject_type' => 'distributor',
        'subject_id' => 1, 'details' => json_encode([]), 'ip' => '127.0.0.1',
        'created_at' => now()->subYears(9),
    ]);

    $this->artisan('compliance:retention-report', ['--force' => true])->assertExitCode(0);

    // The expired orientation view is gone; the recent one and the audit row
    // are untouched — deleting audit rows would break the hash chain.
    expect(DB::table('orientation_views')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'ancient.action')->count())->toBe(1);
});

it('RET-05: purging a non-purgeable category is refused', function () {
    // A caller asking to purge the audit log has misunderstood something and
    // should hear about it rather than get a silent no-op.
    expect(fn () => app(RetentionPolicy::class)->purge('audit_log'))
        ->toThrow(InvalidArgumentException::class, 'not a purgeable retention category');
});

it('RET-06: categories needing a human decision are surfaced', function () {
    DB::table('audit_log')->insert([
        'actor_id' => null, 'action' => 'ancient.action', 'subject_type' => 'distributor',
        'subject_id' => 1, 'details' => json_encode([]), 'ip' => '127.0.0.1',
        'created_at' => now()->subYears(9),
    ]);

    // Showing the count is what stops the question being forgotten. Answering
    // it here would answer it wrongly, and silently.
    $this->artisan('compliance:retention-report')
        ->expectsOutputToContain('past retention and not purgeable here')
        ->assertExitCode(0);
});
