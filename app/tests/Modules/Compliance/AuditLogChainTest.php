<?php

declare(strict_types=1);

/**
 * The audit log is tamper-evident (T-6.1 finding M-1).
 *
 * ALC-01: every new row is linked into the chain automatically
 * ALC-02: an intact chain verifies and prints a head
 * ALC-03: editing a row breaks the chain
 * ALC-04: deleting a row breaks the chain
 * ALC-05: rows written before the chain existed are skipped, not failed
 * ALC-06: a rewritten chain is caught by the recorded head
 */

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function alcEntry(string $action, array $details = []): AuditLog
{
    return AuditLog::create([
        'actor_id' => null,
        'action' => $action,
        'subject_type' => 'distributor',
        'subject_id' => 1,
        'details' => $details,
        'ip' => '127.0.0.1',
    ]);
}

it('ALC-01: links every new row into the chain without being asked', function () {
    // Every module writes audit rows through the model directly. A chain that
    // needed each of a dozen call sites to remember it would have holes.
    $first = alcEntry('kyc.approved');
    $second = alcEntry('distributor.frozen');

    expect($first->row_hash)->not->toBeNull()
        ->and($first->prev_hash)->toBeNull()
        ->and($second->prev_hash)->toBe($first->row_hash);
});

it('ALC-02: an intact chain verifies and prints a head', function () {
    alcEntry('kyc.approved');
    alcEntry('distributor.frozen');
    alcEntry('settings.changed');

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('Audit log intact — 3 rows verified.')
        ->assertExitCode(0);
});

it('ALC-03: editing a row breaks the chain', function () {
    alcEntry('kyc.approved');
    $target = alcEntry('distributor.frozen');
    alcEntry('settings.changed');

    // The scenario this exists for: somebody with database access rewrites
    // what an action was, after the fact.
    DB::table('audit_log')->where('id', $target->id)->update(['action' => 'distributor.unfrozen']);

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('AUDIT LOG CHAIN BROKEN')
        ->assertExitCode(1);
});

it('ALC-04: deleting a row breaks the chain', function () {
    alcEntry('kyc.approved');
    $target = alcEntry('distributor.frozen');
    alcEntry('settings.changed');

    // Deletion is the harder case: the surviving rows still hash correctly
    // against themselves, so only the prev_hash link catches it.
    DB::table('audit_log')->where('id', $target->id)->delete();

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('AUDIT LOG CHAIN BROKEN')
        ->assertExitCode(1);
});

it('ALC-05: rows predating the chain are skipped rather than failed', function () {
    // Back-filling these would mean attesting to history nobody witnessed.
    // A fresh database already has one such row — a migration writes an audit
    // entry directly through the query builder — so this is not hypothetical.
    DB::table('audit_log')->insert([
        'actor_id' => null, 'action' => 'legacy.action', 'subject_type' => 'distributor',
        'subject_id' => 1, 'details' => json_encode([]), 'ip' => '127.0.0.1',
        'created_at' => now()->subYear(),
    ]);

    alcEntry('kyc.approved');

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('predate the hash chain and were skipped.')
        ->expectsOutputToContain('Audit log intact')
        ->assertExitCode(0);
});

it('ALC-06: a wholesale rewrite is caught by the recorded head', function () {
    alcEntry('kyc.approved');
    alcEntry('distributor.frozen');

    $head = bin2hex((string) AuditLog::query()->orderByDesc('id')->value('row_hash'));

    // Anyone who can run this code can recompute the chain from the point they
    // tamper — the internal check would pass. What they cannot change is a
    // head somebody already wrote down elsewhere.
    $this->artisan('compliance:verify-audit-log', ['--head' => $head])->assertExitCode(0);

    DB::table('audit_log')->delete();
    alcEntry('kyc.approved');

    $this->artisan('compliance:verify-audit-log', ['--head' => $head])
        ->expectsOutputToContain('The head does not match')
        ->assertExitCode(1);
});
