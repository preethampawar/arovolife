<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\BankDetailsUpdatedNotification;
use App\Modules\Shared\Crypto\PiiCrypter;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Self-service bank details (QA F70 + F28).
 *
 * A distributor who skipped the wizard's optional bank step used to sit on
 * `no_bank_account` until an admin keyed an account in for them. This page is
 * the missing path, and it carries the guards that make handing it over safe:
 * the account number typed twice, an emailed OTP before anything is written,
 * PiiCrypter ciphertext at rest, and an audit row that only ever names the
 * last 4 digits.
 *
 * Every account number below is obviously fake padding digits — never a
 * plausible real one, in a fixture or anywhere else.
 */
beforeEach(function (): void {
    Cache::flush();
    disableTestForeignKeys();
});

/** Fake account number: leading zeros, last-4 1234. */
const BANK_TEST_ACCOUNT = '000000001234';

function bankDistributor(array $overrides = []): Distributor
{
    return Distributor::factory()->create(array_merge([
        'pan_last4' => '123F',
        'aadhaar_last4' => '9012',
        'bank_account_enc' => null,
        'bank_ifsc' => null,
    ], $overrides));
}

function bankSubmit(User $user, array $overrides = [])
{
    return test()->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.bank.update'), array_merge([
            'account_number' => BANK_TEST_ACCOUNT,
            'account_number_confirmation' => BANK_TEST_ACCOUNT,
            'ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank, Kukatpally',
            'beneficiary_name' => 'K Ramakrishna',
        ], $overrides));
}

function bankConfirm(User $user, string $code)
{
    return test()->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('profile.bank.otp.confirm'), ['otp' => $code]);
}

/** The 6-digit code from the (faked) OTP email. */
function bankCapturedOtp(): string
{
    $code = null;
    Notification::assertSentOnDemand(OtpCodeNotification::class, function ($notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return (string) $code;
}

it('BANK-01: renders the form with its purpose note and the masked account on file', function (): void {
    $distributor = bankDistributor([
        'bank_account_enc' => PiiCrypter::encryptString(BANK_TEST_ACCOUNT),
        'bank_ifsc' => 'HDFC0001234',
    ]);

    $this->actingAs($distributor->user)->get(route('profile.bank.show'))
        ->assertOk()
        ->assertSee('What this form does')
        ->assertSee('••••1234', false)
        ->assertSee('HDFC0001234')
        ->assertDontSee(BANK_TEST_ACCOUNT);
});

it('BANK-02: rejects two account numbers that do not match — nothing written, no code sent', function (): void {
    Notification::fake();
    $distributor = bankDistributor();

    bankSubmit($distributor->user, ['account_number_confirmation' => '000000009999'])
        ->assertSessionHasErrors('account_number');

    Notification::assertNothingSent();
    expect($distributor->refresh()->bank_account_enc)->toBeNull();
});

it('BANK-03: rejects a malformed IFSC', function (): void {
    Notification::fake();
    $distributor = bankDistributor();

    bankSubmit($distributor->user, ['ifsc' => 'HDFC1001234'])
        ->assertSessionHasErrors('ifsc');

    Notification::assertNothingSent();
    expect($distributor->refresh()->bank_ifsc)->toBeNull();
});

it('BANK-04: holds the change behind an OTP, then writes it encrypted with an audit row', function (): void {
    Notification::fake();
    $distributor = bankDistributor();

    bankSubmit($distributor->user)
        ->assertRedirect(route('profile.bank.show'))
        ->assertSessionHas('bank_otp');

    // Nothing written until the code is confirmed.
    expect($distributor->refresh()->bank_account_enc)->toBeNull();

    bankConfirm($distributor->user, bankCapturedOtp())
        ->assertRedirect(route('profile.bank.show'))
        ->assertSessionHas('status');

    $distributor->refresh();
    expect(PiiCrypter::decryptString((string) $distributor->bank_account_enc))->toBe(BANK_TEST_ACCOUNT)
        ->and(PiiCrypter::decryptString((string) $distributor->bank_beneficiary_name_enc))->toBe('K Ramakrishna')
        ->and($distributor->bank_ifsc)->toBe('HDFC0001234')
        ->and($distributor->bank_name)->toBe('HDFC Bank, Kukatpally');

    // Ciphertext, not the number, is what is actually on disk.
    $stored = (string) DB::table('distributors')->where('id', $distributor->id)->value('bank_account_enc');
    expect($stored)->not->toContain(BANK_TEST_ACCOUNT);

    $audit = AuditLog::where('action', 'distributor.bank_details_updated')
        ->where('subject_id', $distributor->id)->sole();
    expect($audit->details['account_last4'])->toBe('1234')
        ->and($audit->details)->not->toHaveKey('account_number')
        ->and($audit->before_hash)->not->toBeNull()
        ->and($audit->after_hash)->not->toBeNull()
        ->and($audit->before_hash)->not->toBe($audit->after_hash);

    Notification::assertSentTo($distributor->user, BankDetailsUpdatedNotification::class);
});

it('BANK-05: a wrong OTP writes nothing', function (): void {
    Notification::fake();
    $distributor = bankDistributor();

    bankSubmit($distributor->user);
    bankConfirm($distributor->user, '000000')->assertSessionHasErrors('otp');

    expect($distributor->refresh()->bank_account_enc)->toBeNull();
    Notification::assertNotSentTo($distributor->user, BankDetailsUpdatedNotification::class);
});

it('BANK-06: never echoes the account number back — not in the page, not in the audit details', function (): void {
    Notification::fake();
    $distributor = bankDistributor();

    bankSubmit($distributor->user);
    bankConfirm($distributor->user, bankCapturedOtp());

    $this->actingAs($distributor->user)->get(route('profile.bank.show'))
        ->assertOk()
        ->assertDontSee(BANK_TEST_ACCOUNT);

    $this->actingAs($distributor->user)->get(route('profile.show'))
        ->assertOk()
        ->assertDontSee(BANK_TEST_ACCOUNT)
        ->assertSee('K Ramakrishna');

    $details = json_encode(AuditLog::where('action', 'distributor.bank_details_updated')->sole()->details);
    expect($details)->not->toContain(BANK_TEST_ACCOUNT);
});

it('BANK-07: leaves PAN and Aadhaar untouched', function (): void {
    Notification::fake();
    $distributor = bankDistributor();
    $panHash = $distributor->pan_hash;

    bankSubmit($distributor->user);
    bankConfirm($distributor->user, bankCapturedOtp());

    $distributor->refresh();
    expect($distributor->pan_last4)->toBe('123F')
        ->and($distributor->aadhaar_last4)->toBe('9012')
        ->and($distributor->pan_hash)->toBe($panHash);
});

it('BANK-08: a payout held as no_bank_account is released once the details are saved', function (): void {
    Notification::fake();
    Queue::fake();
    DB::table('settings')->updateOrInsert(
        ['key' => 'payout.gateway'],
        ['value' => 'manual_neft', 'version' => 1, 'updated_at' => now()],
    );

    $distributor = bankDistributor();
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 900_000 + $distributor->id,
        'bv_paise' => 300_000,          // 3,000 BV — clears the Retailer gate
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
    app(WalletService::class)->credit(
        $distributor->id, 100_000, 'gsb_credit', walletRef(), 'test_reference',
        earnedOn: PayoutBatch::weeklyEarningWindow(Carbon::today())['end'],
    );

    $payouts = app(PayoutService::class);
    $batch = $payouts->runWeeklyBatch(Carbon::today());
    expect(PayoutLineItem::where('distributor_id', $distributor->id)->sole()->status)
        ->toBe(PayoutLineItem::STATUS_NO_BANK_ACCOUNT);

    bankSubmit($distributor->user);
    bankConfirm($distributor->user, bankCapturedOtp());

    // Holds are re-read from live state at approval (F93) — the distributor's
    // own fix releases their money without an admin touching anything.
    $payouts->approve($batch, User::factory()->create()->id);

    expect(PayoutLineItem::where('distributor_id', $distributor->id)->sole()->status)
        ->toBe(PayoutLineItem::STATUS_PENDING);
});

it('BANK-09: a user without a distributor record cannot reach the page', function (): void {
    $this->actingAs(User::factory()->create())->get(route('profile.bank.show'))->assertForbidden();
});
