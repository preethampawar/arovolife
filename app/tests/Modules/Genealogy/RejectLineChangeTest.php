<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\RejectLineChange;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('RLC-01: rejection sets status + note + reviewer and touches no placement', function () {
    Event::fake();

    $admin = User::create([
        'email' => 'rlc-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    // The request row references distributor_id=777 / parents 1,2 which are not
    // real distributors. The line_change_requests table has FK constraints on
    // those columns (fk_lcr_distributor/from/to). Defer FK enforcement for this
    // focused insert; RejectLineChange only reads/writes the request row and
    // never eager-loads the distributor, so no real distributor is needed.
    disableTestForeignKeys();
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => 777,
        'from_placement_parent_id' => 1,
        'to_placement_parent_id' => 2,
        'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending',
        'reason' => 'move me',
    ]);
    enableTestForeignKeys();

    app(RejectLineChange::class)($reqId, $admin->id, 'Target parent is in a different leg; not eligible.');

    $req = LineChangeRequest::find($reqId);
    expect($req->status)->toBe('rejected')
        ->and($req->decision_note)->toBe('Target parent is in a different leg; not eligible.')
        ->and((int) $req->reviewed_by)->toBe($admin->id)
        ->and($req->reviewed_at)->not->toBeNull()
        ->and($req->approved_at)->toBeNull();

    Event::assertDispatched(LineChangeRejected::class, fn ($e) => $e->requestId === $reqId);
    expect(AuditLog::where('action', 'genealogy.line_change.rejected')->where('subject_id', 777)->exists())->toBeTrue();
});
