<?php

declare(strict_types=1);

/**
 * Developer-only partner subtree erasure. Fixture: root → A (L) and X (R);
 * A → B (L), C (R); B → D (L). Every partner below A is sponsored by A.
 *
 * Every disk the service deletes from is faked: a test that forgot once wiped
 * real staging KYC scans (2026-10-18).
 */

use App\Modules\Admin\Services\Erasure\PartnerErasureRefused;
use App\Modules\Admin\Services\Erasure\PartnerErasureService;
use App\Modules\Admin\Services\Erasure\PartnerErasureTableMap;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorRequestService;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['kyc', 's3', DistributorRequestService::DISK, AreteCenterApplicationService::DISK, OfflinePayment::DISK, 'grievance', 'private', 'db-backups'] as $disk) {
        Storage::fake($disk);
    }
    // Outside the 00:00–05:00 IST engine window, whatever the wall clock says.
    Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00', 'Asia/Kolkata'));
    Event::fake([PlacementCreated::class, DistributorRegistered::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function erasureStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function erasurePlace(int $sponsorId, int $placementId, string $side): int
{
    $user = User::factory()->create();

    return app(PlacementEngine::class)->place(new PlaceDistributorInput(
        userId: $user->id,
        sponsorId: $sponsorId,
        placementId: $placementId,
        panHash: random_bytes(32),
        panLast4: '1234',
        bankAccountEnc: 'stub',
        bankIfsc: 'SBIN0000000',
        state: 'TS',
        sideOpt: $side,
    ))->distributorId;
}

/**
 * @return array{root: int, a: int, b: int, c: int, d: int, x: int}
 */
function erasureTree(): array
{
    $root = uiDistributor(['full_name' => 'Root'])['id'];
    $a = erasurePlace($root, $root, 'L');
    $x = erasurePlace($root, $root, 'R');
    $b = erasurePlace($a, $a, 'L');
    $c = erasurePlace($a, $a, 'R');
    $d = erasurePlace($a, $b, 'L');

    return ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'x' => $x];
}

function erasureAdn(int $id): string
{
    return (string) DB::table('distributors')->where('id', $id)->value('adn');
}

function erasureUserId(int $distributorId): int
{
    return (int) DB::table('distributors')->where('id', $distributorId)->value('user_id');
}

/**
 * Insert one row with every required column filled from the schema, so a row
 * can be planted in any table without knowing its shape.
 *
 * @param  array<string, mixed>  $values
 */
function erasureRow(string $table, array $values): int
{
    $row = [];
    foreach (Schema::getColumns($table) as $column) {
        $name = $column['name'];
        if (array_key_exists($name, $values) || $column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
            continue;
        }
        $type = strtolower($column['type']);
        $row[$name] = match (true) {
            str_starts_with($type, 'enum(') => trim(explode(',', substr($type, 5))[0], "')"),
            str_contains($type, 'int'), str_starts_with($type, 'decimal') => 1,
            str_starts_with($type, 'datetime'), str_starts_with($type, 'timestamp') => now()->format('Y-m-d H:i:s'),
            str_starts_with($type, 'date') => '2026-01-01',
            str_starts_with($type, 'time') => '09:00:00',
            str_starts_with($type, 'json') => '{}',
            default => Str::lower(Str::random(min(6, (int) (preg_match('/\((\d+)\)/', $type, $m) === 1 ? $m[1] : 6)))),
        };
    }

    disableTestForeignKeys();
    try {
        return (int) DB::table($table)->insertGetId([...$row, ...$values]);
    } finally {
        enableTestForeignKeys();
    }
}

function erasureDeveloper(): User
{
    return erasureStaff('developer');
}

/** The first blocker code the call was refused with, or null when it ran. */
function erasureRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (PartnerErasureRefused $e) {
        return $e->blockers[0]['code'];
    }

    return null;
}

/**
 * @return list<string> every blocker code the call was refused with
 */
function erasureRefusals(Closure $call): array
{
    try {
        $call();
    } catch (PartnerErasureRefused $e) {
        return array_column($e->blockers, 'code');
    }

    return [];
}

function erasureService(): PartnerErasureService
{
    return app(PartnerErasureService::class);
}

function erasureRun(string $adn, User $actor): void
{
    $preview = erasureService()->preview($adn);
    erasureService()->execute($adn, $preview->fingerprint, $actor);
}

/**
 * A partner's customer identity and one delivered order.
 *
 * @return array{customer: int, order: int}
 */
function erasureCommerce(int $distributorId): array
{
    $customerId = erasureRow('customers', [
        'user_id' => erasureUserId($distributorId),
        'distributor_id' => $distributorId,
        'display_name' => 'Asha Rao',
        'email_hash' => str_repeat('a', 64),
        'email_enc' => 'cipher',
        'phone_hash' => str_repeat('b', 64),
        'phone_enc' => 'cipher',
        'phone_last4' => '4321',
        'marketing_opt_in' => true,
    ]);
    $orderId = erasureRow('orders', [
        'customer_id' => $customerId,
        'order_no' => 'ORD'.random_int(100000, 999999),
        'status' => 'delivered',
        'ship_name' => 'Asha Rao',
        'ship_phone_e164' => '+919800000000',
        'ship_line1' => '12 MG Road',
        'ship_line2' => 'Flat 4',
        'ship_city' => 'Hyderabad',
        'ship_state' => 'TS',
        'ship_pincode' => '500001',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    return ['customer' => $customerId, 'order' => $orderId];
}

it('previews the subtree with each member on the target\'s leg and a stable fingerprint', function (): void {
    $t = erasureTree();
    $preview = erasureService()->preview(erasureAdn($t['a']));

    $legs = collect($preview->members)->pluck('leg', 'id')->all();
    expect($preview->targetId)->toBe($t['a'])
        ->and(collect($preview->members)->pluck('id')->sort()->values()->all())->toBe([$t['b'], $t['c'], $t['d']])
        ->and($legs)->toBe([$t['b'] => 'L', $t['c'] => 'R', $t['d'] => 'L'])
        ->and($preview->leftAdns)->toEqualCanonicalizing([erasureAdn($t['b']), erasureAdn($t['d'])])
        ->and($preview->rightAdns)->toBe([erasureAdn($t['c'])])
        ->and($preview->canErase())->toBeTrue()
        ->and($preview->fingerprint)->toBe(hash('sha256', implode(',', [$t['a'], $t['b'], $t['c'], $t['d']])))
        ->and(erasureService()->preview(erasureAdn($t['a']))->fingerprint)->toBe($preview->fingerprint);
});

it('takes the subtree out of the tree and frees the parent\'s slot', function (): void {
    $t = erasureTree();
    $xClosure = DB::table('genealogy_closure')->where('descendant_id', $t['x'])->count();
    $set = [$t['a'], $t['b'], $t['c'], $t['d']];

    $preview = erasureService()->preview(erasureAdn($t['a']));
    $result = erasureService()->execute(erasureAdn($t['a']), $preview->fingerprint, erasureDeveloper());

    expect($result->erasedCount)->toBe(4)
        ->and($result->backupFile)->toBeNull()
        ->and(DB::table('genealogy_closure')->whereIn('descendant_id', $set)->orWhereIn('ancestor_id', $set)->count())->toBe(0)
        ->and(DB::table('genealogy_closure')->where('descendant_id', $t['x'])->count())->toBe($xClosure)
        ->and(app(TeamStatsService::class)->scopedCount(Distributor::query()->findOrFail($t['root']), 'total'))->toBe(1);

    $a = Distributor::query()->findOrFail($t['a']);
    expect($a->isErased())->toBeTrue()
        ->and($a->adn)->toBe("ERASED-{$t['a']}")
        ->and($a->placement_parent_id)->toBe($t['a'])
        ->and($a->sponsor_id)->toBe($t['a'])
        ->and($a->placement_side)->toBeNull()
        ->and($a->depth)->toBe(0)
        ->and(Distributor::query()->findOrFail($t['x'])->placement_parent_id)->toBe($t['root']);

    // The root's Left slot is open again.
    $joiner = erasurePlace($t['root'], $t['root'], 'L');
    expect(Distributor::query()->findOrFail($joiner)->placement_parent_id)->toBe($t['root']);
});

it('deletes every personal row, anonymises the kept rows and removes the files', function (): void {
    $t = erasureTree();
    $aUser = erasureUserId($t['a']);
    $xUser = erasureUserId($t['x']);
    $email = (string) DB::table('users')->where('id', $aUser)->value('email');
    ['customer' => $customerId, 'order' => $orderId] = erasureCommerce($t['a']);

    $requestId = erasureRow('distributor_requests', ['distributor_id' => $t['b']]);
    $applicationId = erasureRow('arete_center_applications', ['distributor_id' => $t['c']]);
    $cartId = erasureRow('carts', ['customer_id' => $customerId]);
    $ticketId = erasureRow('tickets', ['distributor_id' => $t['d']]);
    $messageId = erasureRow('messages', ['from_user_id' => $aUser, 'to_user_id' => $xUser]);
    User::findOrFail($aUser)->assignRole(Role::firstOrCreate(['name' => 'distributor', 'guard_name' => 'web']));
    User::findOrFail($aUser)->givePermissionTo(Permission::firstOrCreate(['name' => 'erasure.test', 'guard_name' => 'web']));

    // [table, key column, value] — one planted row per DELETE table.
    $planted = [
        ['distributor_request_documents', 'request_id', $requestId, ['object_storage_key' => 'req/doc.pdf']],
        ['distributor_requests', 'id', $requestId, null],
        ['arete_center_application_documents', 'application_id', $applicationId, ['object_storage_key' => 'adc/doc.pdf']],
        ['arete_center_application_declarations', 'application_id', $applicationId, []],
        ['arete_center_applications', 'id', $applicationId, null],
        ['cart_items', 'cart_id', $cartId, []],
        ['carts', 'id', $cartId, null],
        ['customer_addresses', 'customer_id', $customerId, []],
        ['ticket_attachments', 'ticket_id', $ticketId, ['disk' => 'grievance', 'path' => 'tickets/a.pdf']],
        ['ticket_events', 'ticket_id', $ticketId, []],
        ['tickets', 'id', $ticketId, null],
        ['message_reports', 'reported_by_user_id', $aUser, ['message_id' => $messageId]],
        ['messages', 'id', $messageId, null],
        ['message_blocks', 'blocker_user_id', $aUser, ['blocked_user_id' => $xUser]],
        ['notifications', 'notifiable_id', $aUser, ['id' => (string) Str::uuid(), 'notifiable_type' => (new User)->getMorphClass()]],
        ['announcement_reads', 'user_id', $aUser, []],
        ['password_reset_tokens', 'email', $email, []],
        ['model_has_roles', 'model_id', $aUser, null],
        ['model_has_permissions', 'model_id', $aUser, null],
        ['group_bv_credits', 'ancestor_id', $t['a'], ['order_id' => $orderId]],
        ['group_bv_reversals', 'ancestor_id', $t['b'], ['order_id' => $orderId]],
        ['genealogy_closure', 'descendant_id', $t['a'], null],
        ['sponsorship', 'distributor_id', $t['a'], null],
        ['kyc_documents', 'distributor_id', $t['a'], ['object_storage_key' => "user_{$aUser}/pan_abc.pdf"]],
    ];
    $byDistributor = [
        'distributor_profiles', 'distributor_nominees', 'distributor_family_members', 'consents', 'orientation_views',
        'cooling_off_events', 'plan_change_notices', 'attribution_touches', 'shared_carts', 'line_change_requests',
        'gsb_reversal_requests', 'arete_center_members', 'bv_ledger_entries', 'bv_propagation_log', 'group_bv_daily',
        'group_bv_debts', 'gsb_carryforward', 'gsb_cutoff_deferrals', 'gsb_personal_bv_topups', 'rank_qualifications',
        'rank_provisional_standings', 'rank_aogo_grants', 'lifetime_award_milestones', 'repurchase_cycles',
        'purchase_offer_grants', 'redeem_point_entries', 'fortune_bonus_participants',
    ];
    foreach ($byDistributor as $table) {
        $planted[] = [$table, 'distributor_id', $t['b'], []];
    }
    expect(collect($planted)->pluck(0)->sort()->values()->all())
        ->toBe(collect(PartnerErasureTableMap::DELETE)->sort()->values()->all());

    foreach ($planted as [$table, $column, $value, $extra]) {
        if ($extra !== null) {
            erasureRow($table, [$column => $value, ...$extra]);
        }
    }
    // An outsider's row in the same table survives, and so does the upline's credit.
    erasureRow('distributor_profiles', ['distributor_id' => $t['x']]);
    erasureRow('group_bv_credits', ['ancestor_id' => $t['root'], 'order_id' => $orderId]);
    erasureRow('mentorship_bonus_results', ['sponsor_id' => $t['root'], 'sponsee_id' => $t['a']]);

    // Rows kept with personal data stripped.
    $paymentEventId = erasureRow('payment_events', ['order_id' => $orderId, 'payload' => json_encode(['id' => 'pay_1', 'amount' => 500, 'Email' => 'asha@example.com', 'notes' => ['x' => 1], 'customer' => ['contact' => '+9198']])]);
    $intentId = erasureRow('payment_intents', ['order_id' => $orderId, 'raw_payload' => json_encode(['order' => ['name' => 'Asha']])]);
    $shipmentEventId = erasureRow('shipment_events', ['order_id' => $orderId, 'payload' => json_encode(['shipping_address' => ['city' => 'Hyd'], 'awb' => 'A1'])]);
    $returnId = erasureRow('return_requests', ['order_id' => $orderId, 'opened_by_customer_id' => $customerId, 'notes' => 'call Asha', 'receipt_note' => 'ok']);
    $offlineId = erasureRow('offline_payments', ['order_id' => $orderId, 'distributor_id' => $t['a'], 'payer_name' => 'Asha', 'notes' => 'n', 'proof_storage_key' => 'proofs/a.pdf', 'proof_original_name' => 'asha.pdf']);
    $batchId = erasureRow('payout_batches', ['status' => PayoutBatch::STATUS_COMPLETED]);
    $lineId = erasureRow('payout_line_items', ['payout_batch_id' => $batchId, 'distributor_id' => $t['a'], 'status' => PayoutLineItem::STATUS_TRANSFERRED, 'bank_account_last4' => '9876']);
    erasureRow('invoices', ['order_id' => $orderId]);
    erasureRow('wallet_ledger_entries', ['distributor_id' => $t['a'], 'amount_paise' => 5000]);
    DB::table('users')->where('id', $aUser)->update(['id_photo_path' => "user_{$aUser}/id-photo/a.jpg", 'address' => '12 MG Road', 'date_of_birth' => '1990-01-01']);

    Storage::disk('kyc')->put("user_{$aUser}/pan_abc.pdf", 'scan');
    Storage::disk('kyc')->put("user_{$aUser}/aadhaar_front.pdf", 'scan');
    Storage::disk('kyc')->put("user_{$xUser}/pan_x.pdf", 'scan');
    Storage::disk('s3')->put("user_{$aUser}/id-photo/a.jpg", 'photo');
    Storage::disk(DistributorRequestService::DISK)->put('req/doc.pdf', 'doc');
    Storage::disk(AreteCenterApplicationService::DISK)->put('adc/doc.pdf', 'doc');
    Storage::disk('grievance')->put('tickets/a.pdf', 'doc');
    Storage::disk(OfflinePayment::DISK)->put('proofs/a.pdf', 'proof');

    $kept = fn (): array => [
        DB::table('wallet_ledger_entries')->count(),
        DB::table('payout_line_items')->count(),
        DB::table('invoices')->count(),
        DB::table('orders')->count(),
        DB::table('mentorship_bonus_results')->where('sponsee_id', $t['a'])->count(),
        DB::table('group_bv_credits')->where('ancestor_id', $t['root'])->count(),
    ];
    $keptBefore = $kept();

    $preview = erasureService()->preview(erasureAdn($t['a']));
    $result = erasureService()->execute(erasureAdn($t['a']), $preview->fingerprint, erasureDeveloper());

    foreach ($planted as [$table, $column, $value]) {
        expect(DB::table($table)->where($column, $value)->count())->toBe(0, "{$table} still has a row for {$column}={$value}");
    }
    expect(DB::table('distributor_profiles')->where('distributor_id', $t['x'])->count())->toBe(1)
        ->and($kept())->toBe($keptBefore);

    $user = User::findOrFail($aUser);
    expect($user->email)->toBe("erased-{$aUser}@erased.invalid")
        ->and($user->phone_e164)->toBe('+0'.str_pad((string) $aUser, 14, '0', STR_PAD_LEFT))
        ->and($user->full_name)->toBe('Erased partner')
        ->and($user->address)->toBeNull()
        ->and($user->id_photo_path)->toBeNull()
        ->and($user->status)->toBe('terminated')
        ->and($user->closure_type)->toBe('erased')
        ->and(Hash::check('password', $user->password_hash))->toBeFalse();

    $a = DB::table('distributors')->where('id', $t['a'])->first();
    expect($a->pan_hash)->toBeNull()->and($a->pan_last4)->toBeNull()->and($a->aadhaar_ref)->toBeNull()
        ->and($a->bank_account_enc)->toBeNull()->and($a->bank_ifsc)->toBeNull()->and($a->state)->toBe('TS')
        ->and($a->status)->toBe('inactive');

    $customer = DB::table('customers')->where('id', $customerId)->first();
    expect($customer->display_name)->toBe('Erased partner')->and($customer->email_hash)->toBeNull()
        ->and($customer->phone_enc)->toBeNull()->and($customer->phone_last4)->toBeNull()
        ->and((bool) $customer->marketing_opt_in)->toBeFalse();

    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->ship_name)->toBe('Erased')->and($order->ship_phone_e164)->toBeNull()->and($order->ship_line1)->toBeNull()
        ->and($order->ship_city)->toBeNull()->and($order->ship_pincode)->toBeNull()->and($order->ship_state)->toBe('TS');

    expect(json_decode((string) DB::table('payment_events')->where('id', $paymentEventId)->value('payload'), true))
        ->toEqual(['id' => 'pay_1', 'amount' => 500, 'Email' => '[erased]', 'notes' => '[erased]', 'customer' => ['contact' => '[erased]']])
        ->and(json_decode((string) DB::table('payment_intents')->where('id', $intentId)->value('raw_payload'), true))
        ->toBe(['order' => ['name' => '[erased]']])
        ->and(json_decode((string) DB::table('shipment_events')->where('id', $shipmentEventId)->value('payload'), true))
        ->toEqual(['shipping_address' => '[erased]', 'awb' => 'A1']);

    $return = DB::table('return_requests')->where('id', $returnId)->first();
    $offline = DB::table('offline_payments')->where('id', $offlineId)->first();
    expect($return->notes)->toBeNull()->and($return->receipt_note)->toBeNull()
        ->and($offline->payer_name)->toBeNull()->and($offline->proof_storage_key)->toBeNull()
        ->and($offline->proof_original_name)->toBeNull()->and($offline->proof_purged_at)->not->toBeNull()
        ->and(DB::table('payout_line_items')->where('id', $lineId)->value('bank_account_last4'))->toBeNull();

    expect(Storage::disk('kyc')->allFiles("user_{$aUser}"))->toBe([])
        ->and(Storage::disk('kyc')->exists("user_{$xUser}/pan_x.pdf"))->toBeTrue()
        ->and(Storage::disk('s3')->allFiles("user_{$aUser}"))->toBe([])
        ->and(Storage::disk(DistributorRequestService::DISK)->exists('req/doc.pdf'))->toBeFalse()
        ->and(Storage::disk(AreteCenterApplicationService::DISK)->exists('adc/doc.pdf'))->toBeFalse()
        ->and(Storage::disk('grievance')->exists('tickets/a.pdf'))->toBeFalse()
        ->and(Storage::disk(OfflinePayment::DISK)->exists('proofs/a.pdf'))->toBeFalse()
        ->and($result->filesDeleted)->toBe(7)
        ->and($result->filesFailed)->toBe(0);
});

it('also removes older copies no row points to, and only the erased people\'s folders', function (): void {
    $t = erasureTree();
    $requestId = erasureRow('distributor_requests', ['distributor_id' => $t['b']]);
    $applicationId = erasureRow('arete_center_applications', ['distributor_id' => $t['c']]);
    $ticketId = erasureRow('tickets', ['distributor_id' => $t['d']]);
    $ticketNo = (string) DB::table('tickets')->where('id', $ticketId)->value('ticket_no');
    $session = str_repeat('a1', 20);
    erasureRow('kyc_documents', ['distributor_id' => $t['a'], 'object_storage_key' => "reg_{$session}/pan_new.pdf"]);

    $gone = [
        ['kyc', "reg_{$session}/pan_old.pdf"],
        ['kyc', "distributor_{$t['a']}/pan_old.pdf"],
        ['kyc', "admin_{$t['b']}/cheque_old.pdf"],
        [DistributorRequestService::DISK, "request_{$requestId}/old.pdf"],
        [AreteCenterApplicationService::DISK, "application_{$applicationId}/old.pdf"],
        ['grievance', "{$ticketNo}/old.pdf"],
    ];
    $kept = [
        ['kyc', "distributor_{$t['x']}/pan.pdf"],
        ['kyc', "distributor_{$t['a']}0/pan.pdf"],
        ['kyc', 'reg_'.str_repeat('b2', 20).'/pan.pdf'],
        [DistributorRequestService::DISK, "request_{$requestId}0/doc.pdf"],
    ];
    foreach ([...$gone, ...$kept] as [$disk, $path]) {
        Storage::disk($disk)->put($path, 'x');
    }

    erasureService()->execute(erasureAdn($t['a']), erasureService()->preview(erasureAdn($t['a']))->fingerprint, erasureDeveloper());

    foreach ($gone as [$disk, $path]) {
        expect(Storage::disk($disk)->exists($path))->toBeFalse("{$disk}:{$path} survived");
    }
    foreach ($kept as [$disk, $path]) {
        expect(Storage::disk($disk)->exists($path))->toBeTrue("{$disk}:{$path} was deleted");
    }
});

it('writes no audit row and leaves the audit chain verifiable', function (): void {
    $adn = erasureAdn(erasureTree()['a']);
    $developer = erasureDeveloper();
    $before = AuditLog::query()->count();
    expect($before)->toBeGreaterThan(0);

    erasureRun($adn, $developer);

    expect(AuditLog::query()->count())->toBe($before)
        ->and(Artisan::call('compliance:verify-audit-log'))->toBe(0);
});

it('refuses on each blocker and erases nothing', function (string $code, Closure $arrange): void {
    $t = erasureTree();
    $adn = erasureAdn($t['a']);
    $fingerprint = erasureService()->preview($adn)->fingerprint;
    $arrange($t);
    $adn = erasureAdn($t['a']);

    $preview = erasureService()->preview($adn);
    expect(collect($preview->blockers)->pluck('code')->all())->toContain($code)
        ->and($preview->canErase())->toBeFalse();

    $developer = erasureDeveloper();
    expect(erasureRefusals(fn () => erasureService()->execute($adn, $fingerprint, $developer)))->toContain($code);

    expect(DB::table('distributors')->whereIn('id', [$t['a'], $t['b'], $t['c'], $t['d']])->whereNotNull('erased_at')->count())->toBe(0)
        ->and(DB::table('genealogy_closure')->where('ancestor_id', $t['a'])->count())->toBe(4);
})->with([
    'reserved member' => ['reserved', fn (array $t) => DB::table('distributors')->where('id', $t['d'])->update(['adn' => ReservedAdns::CHILDREN[40]])],
    'open order' => ['open_order', fn (array $t) => DB::table('orders')->where('id', erasureCommerce($t['b'])['order'])->update(['status' => 'paid'])],
    'order in cooling-off' => ['cooling_off_open', fn (array $t) => erasureRow('order_cooling_off', [
        'order_id' => erasureCommerce($t['b'])['order'], 'opened_at' => now()->subDays(5), 'ends_at' => now()->addDays(25), 'status' => 'open',
    ])],
    'refund still owed' => ['refund_unpaid', fn (array $t) => erasureRow('refund_intents', [
        'order_id' => erasureCommerce($t['b'])['order'], 'amount_paise' => 50000, 'status' => 'created', 'reason_code' => 'cooling_off', 'idempotency_key' => 'erasure-test-refund',
    ])],
    'ADC owner' => ['adc_owner', fn (array $t) => erasureRow('arete_centers', ['name' => 'Kukatpally', 'assigned_distributor_id' => $t['c']])],
    'payout in flight' => ['payout_in_flight', fn (array $t) => erasureRow('payout_line_items', [
        'payout_batch_id' => erasureRow('payout_batches', ['status' => PayoutBatch::STATUS_APPROVED]),
        'distributor_id' => $t['b'],
        'status' => PayoutLineItem::STATUS_PENDING,
    ])],
    'outsider line change' => ['line_change_pending', fn (array $t) => erasureRow('line_change_requests', [
        'distributor_id' => $t['x'], 'from_placement_parent_id' => $t['root'], 'to_placement_parent_id' => $t['b'], 'status' => 'pending',
    ])],
    'sponsored outside' => ['sponsored_outside', function (array $t): void {
        $y = erasurePlace($t['root'], $t['x'], 'L');
        DB::table('sponsorship')->where('distributor_id', $y)->update(['sponsor_id' => $t['b']]);
    }],
]);

it('refuses an unknown, an already erased, and the root APN', function (): void {
    $t = erasureTree();
    $refusal = fn (string $adn): ?string => erasureRefusal(fn () => erasureService()->preview($adn));

    expect($refusal('123456789'))->toBe('not_found');

    erasureRun(erasureAdn($t['c']), erasureDeveloper());
    expect($refusal("ERASED-{$t['c']}"))->toBe('already_erased');

    $root = erasureService()->preview(erasureAdn($t['root']));
    expect(collect($root->blockers)->pluck('code')->all())->toContain('root');
});

it('refuses inside the engine window and erases nothing', function (): void {
    $adn = erasureAdn(erasureTree()['a']);
    $fingerprint = erasureService()->preview($adn)->fingerprint;
    Carbon::setTestNow(Carbon::parse('2026-11-24 02:00:00', 'Asia/Kolkata'));

    expect(fn () => erasureService()->execute($adn, $fingerprint, erasureDeveloper()))
        ->toThrow(PartnerErasureRefused::class)
        ->and(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0);
});

it('refuses when the tree changed after the preview', function (): void {
    $t = erasureTree();
    $adn = erasureAdn($t['a']);
    $preview = erasureService()->preview($adn);
    erasurePlace($t['a'], $t['d'], 'L');

    $developer = erasureDeveloper();
    expect(erasureRefusal(fn () => erasureService()->execute($adn, $preview->fingerprint, $developer)))->toBe('tree_changed')
        ->and(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0);
});

it('refuses a non-developer actor, even an admin', function (string $role): void {
    $adn = erasureAdn(erasureTree()['a']);
    $fingerprint = erasureService()->preview($adn)->fingerprint;

    expect(fn () => erasureService()->execute($adn, $fingerprint, erasureStaff($role)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0);
})->with(['admin', 'admin-finance', 'distributor']);

it('erases the couple partner with the primary', function (): void {
    $t = erasureTree();
    $spouseUser = User::factory()->create();
    $now = now()->format('Y-m-d H:i:s.v');
    $spouse = DB::table('distributors')->insertGetId([
        'user_id' => $spouseUser->id,
        'adn' => erasureAdn($t['b']).'-S',
        'sponsor_id' => $t['a'],
        'placement_parent_id' => $t['b'],
        'placement_side' => null,
        'side_chosen_by' => 'referral_explicit',
        'depth' => 2,
        'effective_date' => $now,
        'cooling_off_end_at' => $now,
        'state' => 'TS',
        'spouse_distributor_id' => $t['b'],
        'is_primary_couple' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('distributors')->where('id', $t['b'])->update(['spouse_distributor_id' => $spouse, 'is_primary_couple' => 1]);

    $preview = erasureService()->preview(erasureAdn($t['a']));
    $spouseRow = collect($preview->members)->firstWhere('id', $spouse);
    expect($spouseRow['isSpouse'])->toBeTrue()->and($spouseRow['leg'])->toBeNull();

    erasureService()->execute(erasureAdn($t['a']), $preview->fingerprint, erasureDeveloper());

    expect(Distributor::query()->findOrFail($spouse)->isErased())->toBeTrue()
        ->and(User::findOrFail($spouseUser->id)->closure_type)->toBe('erased');
});

it('erases nothing on production when the backup fails', function (): void {
    $adn = erasureAdn(erasureTree()['a']);
    $fingerprint = erasureService()->preview($adn)->fingerprint;
    Process::fake(['*mysqldump*' => Process::result(errorOutput: 'mysqldump: Got error: 2013', exitCode: 2)]);
    $env = app()['env'];
    app()['env'] = 'production';

    try {
        expect(fn () => erasureService()->execute($adn, $fingerprint, erasureDeveloper()))
            ->toThrow(RuntimeException::class, 'mysqldump exited');
    } finally {
        app()['env'] = $env;
    }

    expect(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0)
        ->and(Storage::disk('db-backups')->allFiles())->toBe([]);
});

it('leaves an erased partner out of the weekly payout roster', function (): void {
    seedCompensationPlanTables();
    $t = erasureTree();
    $wallet = app(WalletService::class);
    $earned = PayoutBatch::weeklyEarningWindow(Carbon::today())['end'];
    $wallet->credit($t['c'], 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: $earned);
    $wallet->credit($t['x'], 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: $earned);

    erasureRun(erasureAdn($t['c']), erasureDeveloper());
    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    expect(PayoutLineItem::query()->where('distributor_id', $t['c'])->exists())->toBeFalse()
        ->and(PayoutLineItem::query()->where('distributor_id', $t['x'])->exists())->toBeTrue();
});

it('refuses to delete from another environment\'s bucket and erases nothing', function (string $env, string $bucket, bool $refused): void {
    $t = erasureTree();
    $adn = erasureAdn($t['a']);
    $fingerprint = erasureService()->preview($adn)->fingerprint;
    $realEnv = app()->environment();
    // Only the configuration changes: the refusal comes before any S3 call,
    // and the allowed case is checked on the preview alone.
    config(['filesystems.disks.kyc' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'ap-south-1', 'bucket' => $bucket]]);
    app()->instance('env', $env);

    try {
        $codes = collect(erasureService()->preview($adn)->blockers)->pluck('code')->all();
        expect(in_array('foreign_bucket', $codes, true))->toBe($refused);

        if ($refused) {
            expect(erasureRefusals(fn () => erasureService()->execute($adn, $fingerprint, erasureDeveloper())))->toContain('foreign_bucket')
                ->and(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0);
        }
    } finally {
        app()->instance('env', $realEnv);
    }
})->with([
    'local on the prod bucket' => ['local', 'arovolife-prod', true],
    'staging on the prod bucket' => ['staging', 'arovolife-prod', true],
    'an unknown environment on S3' => ['testing', 'arovolife-local', true],
    'local on its own bucket' => ['local', 'arovolife-local', false],
    'local on the staging bucket (allowed for now)' => ['local', 'arovolife-staging', false],
    'staging on its own bucket' => ['staging', 'arovolife-staging', false],
    'production on its own bucket' => ['production', 'arovolife-prod', false],
]);

it('keeps an outsider\'s abuse report and the reported message, and drops the rest', function (): void {
    $t = erasureTree();
    $aUser = erasureUserId($t['a']);
    $xUser = erasureUserId($t['x']);

    $reported = erasureRow('messages', ['from_user_id' => $aUser, 'to_user_id' => $xUser]);
    $outsiderReport = erasureRow('message_reports', ['message_id' => $reported, 'reported_by_user_id' => $xUser, 'category' => 'income_claim']);
    $unreported = erasureRow('messages', ['from_user_id' => $aUser, 'to_user_id' => $xUser]);
    $toErased = erasureRow('messages', ['from_user_id' => $xUser, 'to_user_id' => $aUser]);
    $ownReport = erasureRow('message_reports', ['message_id' => $toErased, 'reported_by_user_id' => $aUser, 'category' => 'other']);

    erasureRun(erasureAdn($t['a']), erasureDeveloper());

    expect(DB::table('messages')->where('id', $reported)->exists())->toBeTrue()
        ->and(DB::table('message_reports')->where('id', $outsiderReport)->exists())->toBeTrue()
        ->and(DB::table('messages')->whereIn('id', [$unreported, $toErased])->count())->toBe(0)
        ->and(DB::table('message_reports')->where('id', $ownReport)->exists())->toBeFalse();
});
