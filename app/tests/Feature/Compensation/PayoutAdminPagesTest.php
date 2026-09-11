<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Crypto\PiiCrypter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function smokeAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('developer');

    return $user;
}

it('renders the payout settings page', function (): void {
    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.payout-settings.index'))
        ->assertOk()
        ->assertSee('Payout configuration')
        ->assertSee('RAZORPAYX_KEY_ID');
});

it('renders a payout batch page with a dispatched line item', function (): void {
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_DISPATCHED,
    ]);
    $dist = Distributor::factory()->create();
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $dist->id,
        'wallet_balance_paise' => 100000,
        'gross_paise' => 100000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100000,
        'status' => PayoutLineItem::STATUS_FAILED,
        'failure_reason' => 'Invalid IFSC code',
        'transfer_mode' => 'neft',
        'retry_count' => 1,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertOk()
        ->assertSee('Invalid IFSC code')
        ->assertSee('Dispatched');
});

it('renders the payout operations help document', function (): void {
    $this->actingAs(smokeAdmin())
        ->get(route('admin.help.show', 'payout-operations'))
        ->assertOk()
        ->assertSee('Payout Operations');
});

it('shows the earning week each weekly batch pays for on the batch list', function (): void {
    // A batch dated Tuesday 22 September pays the week that closed on Tuesday
    // 15 September (the client's own offset). Admins reconciling a batch have
    // to be able to see which week it covers without recomputing it by hand —
    // and the column reads the date the batch recorded for itself.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-22',
        'earnings_through' => '2026-09-15',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.index'))
        ->assertOk()
        ->assertSee('Earnings through')
        ->assertSee('22 Sep 2026')
        ->assertSee('15 Sep 2026')
        // Never the statutory term: cooling-off is the 30-day cancellation window.
        ->assertDontSee('cooling-off');
});

it('leaves Earnings through blank for batches that recorded no earning week', function (): void {
    // Legacy `gsb_weekly` batches, and `weekly` batches written before the
    // column existed, settled the wallet balance as it stood on the batch date.
    // They carry no earning week, and the report must not invent one from the
    // batch date — that would misstate what a distributor was actually paid for.
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-08-18',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_GSB_WEEKLY,
        'batch_date' => '2026-09-29',
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.index'))
        ->assertOk()
        ->assertSee('18 Aug 2026')
        ->assertSee('29 Sep 2026')
        // Neither batch's window is printed: 11 Aug and 22 Sep must not appear.
        ->assertDontSee('11 Aug 2026')
        ->assertDontSee('22 Sep 2026');
});

it('reads the earning window off the batch and never re-derives it', function (): void {
    $stamped = new PayoutBatch([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-22',
        'earnings_through' => '2026-09-15',
    ]);
    $unstamped = new PayoutBatch(['batch_type' => PayoutBatch::TYPE_WEEKLY, 'batch_date' => '2026-09-22']);
    $legacy = new PayoutBatch(['batch_type' => PayoutBatch::TYPE_GSB_WEEKLY, 'batch_date' => '2026-09-22']);

    expect($stamped->weeklyEarningThrough()?->toDateString())->toBe('2026-09-15')
        ->and($unstamped->weeklyEarningThrough())->toBeNull()
        ->and($legacy->weeklyEarningThrough())->toBeNull();
});

it('excludes the repurchase wallet from the admin Pending payouts tile', function (): void {
    // QA F89: the tile summed the raw ledger, so the repurchase wallet — a pot
    // that never reaches a bank — was reported as cash queued for transfer.
    $dist = Distributor::factory()->create();

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $dist->id, 'type' => 'gsb_credit', 'amount_paise' => 100_000, 'created_at' => now()],
        ['distributor_id' => $dist->id, 'type' => 'repurchase_transfer', 'amount_paise' => -10_000, 'created_at' => now()],
        ['distributor_id' => $dist->id, 'type' => 'repurchase_deduction', 'amount_paise' => 10_000, 'created_at' => now()],
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.overview'))
        ->assertOk()
        // Cash payable is ₹900, not the ₹1,000 the whole ledger sums to.
        ->assertSee('₹900.00')
        ->assertDontSee('₹1,000.00');
});

it('refuses the NEFT file for a batch nobody has approved', function (): void {
    // QA F95: the export downloaded a header-only file for an unapproved batch.
    // The file is the instruction the bank acts on; it must not exist before
    // finance has signed the amount off.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $this->actingAs(smokeAdmin())
        ->from(route('admin.compensation.weekly-payouts.show', $batch))
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertRedirect(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertSessionHas('error');

    $batch->update(['status' => PayoutBatch::STATUS_APPROVED]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('keeps the NEFT file to finance', function (): void {
    // Every admin role could pull it. It names every payee and their bank
    // digits, and will carry full account numbers once it becomes a real
    // bank-upload file (QA F95).
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
    ]);

    $compliance = User::factory()->create(['status' => 'active']);
    $compliance->assignRole('admin-compliance');

    expect($compliance->can('finance.record'))->toBeFalse();

    $this->actingAs($compliance)
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertForbidden();
});

it('posts the monthly batch actions at the monthly routes and names the held income before approval', function (): void {
    // QA F97: every button on the monthly page submitted to `weekly-payouts/*`,
    // and the confirmation offered "₹0.00 to 0 distributor(s)" while the batch
    // sat on held income.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => now()->startOfMonth()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'total_net_paise' => 0,
        'distributor_count' => 0,
    ]);
    $dist = Distributor::factory()->create();
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $dist->id,
        'wallet_balance_paise' => 7_989_000,
        'gross_paise' => 7_989_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 0,
        'status' => PayoutLineItem::STATUS_KYC_PENDING,
    ]);

    $this->actingAs(smokeAdmin())
        ->get(route('admin.compensation.monthly-payouts.show', $batch))
        ->assertOk()
        ->assertSee(route('admin.compensation.monthly-payouts.approve', $batch))
        ->assertDontSee(route('admin.compensation.weekly-payouts.approve', $batch))
        // The held total the confirmation now names, beside the ₹0 being approved.
        ->assertSee('₹79,890.00 of income for 1 distributor(s) is held', false)
        // The column holds gross minus the credit-time repurchase deduction.
        ->assertSee('Payable before deductions')
        ->assertDontSee('Wallet balance at time of batch generation');
});

it('keeps approving a payout batch out of the hands that build and settle it', function (): void {
    // QA F94: approve, reconcile and retry all shared `finance.record`, so the
    // role that runs a batch and settles it against the bank also signed it
    // off. Approving is `finance.approve` now, and admin-finance does not hold
    // it; admin does.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    expect($finance->can('finance.record'))->toBeTrue()
        ->and($finance->can('finance.approve'))->toBeFalse();

    $this->actingAs($finance)
        ->post(route('admin.compensation.weekly-payouts.approve', $batch))
        ->assertForbidden();

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PENDING);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('admin.compensation.weekly-payouts.approve', $batch))
        ->assertRedirect(route('admin.compensation.weekly-payouts.show', $batch));

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED)
        ->and($batch->fresh()->approved_by)->toBe($admin->id);
});

it('refuses to let the admin who created a batch approve it', function (): void {
    // The second half of maker-checker: holding `finance.approve` is not enough
    // if you are the hand that built this batch (QA F94).
    $maker = User::factory()->create(['status' => 'active']);
    $maker->assignRole('admin');

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'created_by' => $maker->id,
    ]);

    $this->actingAs($maker)
        ->from(route('admin.compensation.weekly-payouts.show', $batch))
        ->post(route('admin.compensation.weekly-payouts.approve', $batch))
        ->assertRedirect(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertSessionHas('error');

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_PENDING)
        ->and(DB::table('audit_log')
            ->where('action', 'payout.batch.self_approval_refused')
            ->where('subject_id', $batch->id)
            ->count())->toBe(1);

    // The batch page tells them why the button is gone.
    $this->actingAs($maker)
        ->get(route('admin.compensation.weekly-payouts.show', $batch))
        ->assertOk()
        ->assertSee('a second person has to approve it')
        ->assertDontSee(route('admin.compensation.weekly-payouts.approve', $batch));

    // A second approver with the same permission signs it off.
    $checker = User::factory()->create(['status' => 'active']);
    $checker->assignRole('admin');

    $this->actingAs($checker)
        ->post(route('admin.compensation.weekly-payouts.approve', $batch))
        ->assertRedirect(route('admin.compensation.weekly-payouts.show', $batch));

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED);
});

it('lets any approver sign off a batch the scheduler built', function (): void {
    // Nobody was logged in when the cron ran, so `created_by` is NULL and the
    // self-approval bar has nobody to bar (QA F94).
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => now()->startOfMonth()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    expect($batch->created_by)->toBeNull();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('admin.compensation.monthly-payouts.approve', $batch))
        ->assertRedirect(route('admin.compensation.monthly-payouts.show', $batch));

    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED);
});

it('stamps the admin who ran the batch as its maker', function (): void {
    // The engine console runs on a queue worker with no session, so the actor
    // comes off the run context EngineRunService binds before calling artisan.
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    app(EngineRunContext::class)
        ->attribute(EngineRun::TRIGGER_MANUAL, $admin->id, null);

    $batch = app(PayoutService::class)
        ->runWeeklyBatch(now()->startOfDay());

    expect($batch->created_by)->toBe($admin->id);
});

it('exports a bank file the bank can execute, and audits every download', function (): void {
    // QA F44/F95: the export carried `Bank Last 4` and no IFSC, so it was a
    // reconciliation sheet while the admin copy called it a bank upload.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
        'total_net_paise' => 150_000,
        'distributor_count' => 2,
    ]);

    // Synthetic fixtures — no real account belongs to anyone here.
    $named = Distributor::factory()->create();
    DB::table('distributors')->where('id', $named->id)->update([
        'bank_account_enc' => PiiCrypter::encryptString('900011112222'),
        'bank_beneficiary_name_enc' => PiiCrypter::encryptString('R Kumar (Savings)'),
        'bank_ifsc' => 'SBIN0001234',
    ]);

    // No beneficiary name on file: the registered name is what the bank gets.
    $unnamed = Distributor::factory()->create();
    DB::table('distributors')->where('id', $unnamed->id)->update([
        'bank_account_enc' => PiiCrypter::encryptString('900033334444'),
        'bank_beneficiary_name_enc' => null,
        'bank_ifsc' => 'HDFC0000567',
    ]);

    foreach ([[$named, 100_000], [$unnamed, 50_000]] as [$dist, $net]) {
        PayoutLineItem::create([
            'payout_batch_id' => $batch->id,
            'distributor_id' => $dist->id,
            'wallet_balance_paise' => $net,
            'gross_paise' => $net,
            'repurchase_deduction_paise' => 0,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'net_transferred_paise' => $net,
            'status' => PayoutLineItem::STATUS_PENDING,
            'transfer_mode' => 'neft',
        ]);
    }

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    $csv = $this->actingAs($finance)
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    expect($csv)
        ->toContain('Line#,ADN,"Beneficiary Name","Account Number",IFSC')
        ->toContain('Narration')
        // The full account number and branch code a bank needs to execute it.
        ->toContain('900011112222')
        ->toContain('SBIN0001234')
        ->toContain('900033334444')
        ->toContain('HDFC0000567')
        // The beneficiary name the distributor gave, and the registered-name
        // fallback for the one who gave none.
        ->toContain('R Kumar (Savings)')
        ->toContain($unnamed->user->full_name)
        // Ungrouped, two decimals — a bank parser reads 1000.00, not 1,000.00.
        ->toContain('1000.00')
        ->toContain('arovolife '.$named->adn.' B'.$batch->id);

    $audit = DB::table('audit_log')
        ->where('action', 'payout.batch.bank_file_exported')
        ->where('subject_id', $batch->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and((int) $audit->actor_id)->toBe($finance->id)
        ->and(json_decode((string) $audit->details, true)['line_count'])->toBe(2)
        // Raw 32 bytes of SHA-256 over the exact file handed over.
        ->and($audit->after_hash)->toBe(AuditLog::digest($csv));

    // Nothing about the account numbers leaked into the audit details.
    expect((string) $audit->details)->not->toContain('900011112222');
});

it('exports a line whose bank details no longer decrypt with no account number', function (): void {
    // A key rotation between the batch run and the download. The line stays
    // visible so finance can see who is unpaid, but cannot be executed.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_APPROVED,
    ]);

    $broken = Distributor::factory()->create();
    DB::table('distributors')->where('id', $broken->id)->update([
        'bank_account_enc' => 'not-valid-ciphertext',
        'bank_ifsc' => 'SBIN0009999',
    ]);

    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $broken->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => PayoutLineItem::STATUS_PENDING,
        'transfer_mode' => 'neft',
    ]);

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    $csv = $this->actingAs($finance)
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain('bank_decrypt_failed')
        ->toContain($broken->adn)
        // No account number, no IFSC — the row cannot be executed by mistake.
        ->not->toContain('SBIN0009999');
});
