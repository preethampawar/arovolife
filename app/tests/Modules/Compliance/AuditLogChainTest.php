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
 * ALC-07: before/after digests are stored as 32 raw bytes, hex or not
 * ALC-08: a row created under `migrate --pretend` stays printable and unwritten
 * ALC-09: details keys in any order still verify after MySQL reorders them
 * ALC-10: two rows written at the same moment share a predecessor without failing
 * ALC-11: first-method rows are link-checked only; version-2 rows are content-checked
 * ALC-12: a row pointing at a hash no row has fails
 * ALC-13: new rows carry hash version 2
 * ALC-14: an edited row cannot be passed off as legacy by clearing its hash version
 * ALC-15: an anchor still matches after more rows are written
 * ALC-16: a row slipped in beside another before the anchor is caught
 * ALC-17: relabelling every row as first-method is caught by the anchor
 * ALC-18: editing a first-method row after the anchor was taken is caught
 */

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Plan migrations write chained audit rows of their own; every case here
// reasons about a chain it builds from nothing.
beforeEach(fn () => DB::table('audit_log')->delete());

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

it('ALC-07: stores before/after digests as 32 raw bytes whether given raw or hex', function () {
    // The columns are BINARY(32). A hex SHA-256 is 64 chars and MySQL refuses
    // it — which cancelled a paid order when markPaid()'s audit row failed.
    // SQLite never enforces the width, so this pins the model-level guard.
    expect(strlen(AuditLog::digest('placed')))->toBe(32);

    $raw = AuditLog::create([
        'actor_id' => null, 'action' => 'order.paid', 'subject_type' => 'order', 'subject_id' => 1,
        'before_hash' => AuditLog::digest('placed'), 'after_hash' => AuditLog::digest('paid'),
        'details' => [], 'ip' => '127.0.0.1',
    ]);
    $hex = AuditLog::create([
        'actor_id' => null, 'action' => 'order.paid', 'subject_type' => 'order', 'subject_id' => 2,
        'before_hash' => hash('sha256', 'placed'), 'after_hash' => hash('sha256', 'paid'),
        'details' => [], 'ip' => '127.0.0.1',
    ]);

    foreach ([$raw, $hex] as $row) {
        $stored = DB::table('audit_log')->where('id', $row->id)->first();
        expect(strlen((string) $stored->before_hash))->toBe(32)
            ->and(strlen((string) $stored->after_hash))->toBe(32)
            ->and($stored->before_hash)->toBe(AuditLog::digest('placed'))
            ->and($stored->after_hash)->toBe(AuditLog::digest('paid'));
    }

});

it('ALC-08: a row created under migrate --pretend stays printable and unwritten', function () {
    // app:deploy dry-runs migrations with --pretend, which prints every query
    // with its bindings substituted. Raw hash bytes cannot be printed, so each
    // audited plan migration crashed the dry run (staging deploy 2026-10-17).
    alcEntry('kyc.approved');

    $queries = DB::pretend(fn () => alcEntry('plan.migration.example'));

    foreach ($queries as $query) {
        DB::getQueryGrammar()->substituteBindingsIntoRawSql($query['query'], $query['bindings']);
    }

    expect($queries)->not->toBeEmpty()
        ->and(AuditLog::query()->count())->toBe(1);
});

it('ALC-09: verifies details whose keys are not in sorted order after MySQL reorders them', function () {
    // The regression: MySQL's JSON column reorders object keys on storage, so
    // hashing the caller's key order could never be recomputed from a read.
    alcEntry('kyc.approved', [
        'side' => 'L', 'depth' => 7,
        'after' => ['status' => 'x', 'city' => 'y'],
        'ids' => [3, 1, 2],
    ]);
    alcEntry('settings.changed', ['zeta' => 1, 'alpha' => ['b' => 1, 'a' => 2]]);

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('Audit log intact — 2 rows verified.')
        ->assertExitCode(0);
});

it('ALC-10: two rows sharing a predecessor verify and are reported', function () {
    $a = alcEntry('kyc.approved');
    alcEntry('distributor.frozen');
    $c = alcEntry('settings.changed');

    // A concurrent writer read the same latest hash as B did.
    $row = AuditLog::query()->find($c->id);
    $row->prev_hash = $a->row_hash;
    DB::table('audit_log')->where('id', $c->id)->update([
        'prev_hash' => $a->row_hash,
        'row_hash' => AuditLog::computeRowHash($row, $a->row_hash),
    ]);

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('Audit log intact — 3 rows verified.')
        ->expectsOutputToContain('share a predecessor.')
        ->assertExitCode(0);
});

it('ALC-11: a first-method row is not failed on content, but a version-2 row after it is', function () {
    // Legacy rows can only sit at the start of the chain; see ALC-14.
    DB::table('audit_log')->insert([
        'actor_id' => null, 'action' => 'legacy.sealed', 'subject_type' => 'distributor',
        'subject_id' => 1, 'details' => json_encode(['b' => 1, 'a' => 2]), 'ip' => '127.0.0.1',
        'created_at' => now(), 'hash_version' => null,
        'row_hash' => str_repeat('x', 32), 'prev_hash' => null,
    ]);
    alcEntry('kyc.approved');
    $c = alcEntry('settings.changed', ['k' => 'v']);

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('sealed by the first hashing method')
        ->assertExitCode(0);

    DB::table('audit_log')->where('id', $c->id)->update(['details' => json_encode(['k' => 'tampered'])]);

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('content does not match its hash')
        ->assertExitCode(1);
});

it('ALC-12: a row pointing at a hash no row has fails', function () {
    alcEntry('kyc.approved');
    $middle = alcEntry('distributor.frozen');
    alcEntry('settings.changed');

    DB::table('audit_log')->where('id', $middle->id)->delete();

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('its link to an earlier row is missing')
        ->assertExitCode(1);
});

it('ALC-13: new rows carry hash version 2', function () {
    $entry = alcEntry('kyc.approved');

    expect($entry->hash_version)->toBe(AuditLog::HASH_VERSION)
        ->and((int) DB::table('audit_log')->where('id', $entry->id)->value('hash_version'))->toBe(2);
});

it('ALC-14: an edited row cannot hide as a first-method row by clearing its hash version', function () {
    alcEntry('kyc.approved');
    $target = alcEntry('distributor.frozen', ['k' => 'v']);
    alcEntry('settings.changed');

    DB::table('audit_log')->where('id', $target->id)->update([
        'details' => json_encode(['k' => 'tampered']),
        'hash_version' => null,
    ]);

    $this->artisan('compliance:verify-audit-log')
        ->expectsOutputToContain('sealed by the old method after the new method began')
        ->assertExitCode(1);
});

function alcAnchor(): string
{
    Artisan::call('compliance:verify-audit-log');
    preg_match('/^Anchor: (\S+)$/m', Artisan::output(), $m);

    return $m[1];
}

it('ALC-15: an anchor still matches after more rows are written', function () {
    alcEntry('kyc.approved');
    alcEntry('distributor.frozen');
    $anchor = alcAnchor();
    alcEntry('settings.changed');

    expect($anchor)->toContain(':2:'.AuditLog::query()->min('id').':');

    $this->artisan('compliance:verify-audit-log', ['--anchor' => $anchor])
        ->expectsOutputToContain('Anchor matches')
        ->assertExitCode(0);
});

it('ALC-16: a row slipped in beside another before the anchor is caught', function () {
    $a = alcEntry('kyc.approved');
    alcEntry('distributor.frozen');
    $anchor = alcAnchor();

    // A forged row that claims the first row as its predecessor, given an id
    // between the two real ones' successors: internally consistent, and the
    // head hash does not move.
    $forged = new AuditLog(['action' => 'payout.approved', 'subject_type' => 'distributor', 'subject_id' => 1, 'details' => ['x' => 1]]);
    $forged->hash_version = AuditLog::HASH_VERSION;
    $forged->created_at = $a->created_at;
    $last = AuditLog::query()->orderByDesc('id')->first();
    DB::table('audit_log')->where('id', $last->id)->update(['id' => $last->id + 1]);
    DB::table('audit_log')->insert([
        'id' => $last->id,
        'action' => 'payout.approved',
        'subject_type' => 'distributor',
        'subject_id' => 1,
        'details' => json_encode(['x' => 1]),
        'created_at' => $a->created_at,
        'hash_version' => AuditLog::HASH_VERSION,
        'prev_hash' => $a->row_hash,
        'row_hash' => AuditLog::computeRowHash($forged, $a->row_hash),
    ]);

    $this->artisan('compliance:verify-audit-log')->assertExitCode(0);
    $this->artisan('compliance:verify-audit-log', ['--anchor' => $anchor])
        ->expectsOutputToContain('The log up to the anchored row has changed.')
        ->assertExitCode(1);
});

it('ALC-17: relabelling every row as first-method is caught by the anchor', function () {
    alcEntry('kyc.approved');
    alcEntry('distributor.frozen');
    $anchor = alcAnchor();

    DB::table('audit_log')->update(['hash_version' => null, 'details' => json_encode(['edited' => true])]);

    $this->artisan('compliance:verify-audit-log')->assertExitCode(0);
    $this->artisan('compliance:verify-audit-log', ['--anchor' => $anchor])
        ->expectsOutputToContain('The log up to the anchored row has changed.')
        ->assertExitCode(1);
});

it('ALC-18: editing a first-method row after the anchor was taken is caught', function () {
    alcEntry('kyc.approved', ['note' => 'original']);
    alcEntry('distributor.frozen');

    // First-method rows: their own hash cannot vouch for their content.
    DB::table('audit_log')->update(['hash_version' => null]);
    $anchor = alcAnchor();

    DB::table('audit_log')->orderBy('id')->limit(1)->update(['details' => json_encode(['note' => 'edited'])]);

    $this->artisan('compliance:verify-audit-log')->assertExitCode(0);
    $this->artisan('compliance:verify-audit-log', ['--anchor' => $anchor])
        ->expectsOutputToContain('The log up to the anchored row has changed.')
        ->assertExitCode(1);
});
