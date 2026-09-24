<?php

declare(strict_types=1);

/**
 * Every bank file of a payout batch is kept (encrypted), shown on a timeline,
 * downloadable by finance, and comparable import against import.
 */

use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutBankFileDiffService;
use App\Modules\Compensation\Services\PayoutBankFileVault;
use App\Modules\Compensation\Services\PayoutReconciliationService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Crypto\PiiCrypter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(PayoutBankFile::DISK);
    setGatewaySetting('payout.gateway', 'manual_neft');
});

function bankFileFinance(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin-finance');

    return $user;
}

/** Import a response file into the batch and return its stored record. */
function importResponse(PayoutBatch $batch, string $csv, ?int $actorId = null): PayoutBankFile
{
    $summary = app(PayoutReconciliationService::class)->import($batch, uploadCsv($csv), $actorId ?? bankFileFinance()->id);

    return PayoutBankFile::findOrFail($summary['bank_file_id']);
}

it('keeps an exported bank file as ciphertext with one sent row per line', function (): void {
    [$batch, $line] = reconcileFixture('ADN3001');
    DB::table('distributors')->where('id', $line->distributor_id)->update([
        'bank_account_enc' => PiiCrypter::encryptString('900055556666'),
        'bank_ifsc' => 'SBIN0001234',
    ]);
    $finance = bankFileFinance();

    $csv = $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.neft', $batch))->assertOk()->streamedContent();
    $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.neft', $batch))->assertOk();

    $files = PayoutBankFile::where('direction', 'export')->orderBy('id')->get();
    $stored = Storage::disk(PayoutBankFile::DISK)->get($files[0]->storage_key);

    expect($files)->toHaveCount(2)
        ->and($csv)->toContain('900055556666')
        ->and($stored)->not->toContain('900055556666')
        ->and(app(PayoutBankFileVault::class)->read($files[0]))->toBe($csv)
        ->and($files[0]->actor_id)->toBe($finance->id)
        ->and($files[0]->rows()->pluck('result')->all())->toBe([PayoutBankFileRow::RESULT_SENT])
        ->and($files[0]->rows()->first()->payout_line_item_id)->toBe($line->id)
        ->and(app(PayoutBankFileDiffService::class)->ordinals($batch))->toBe([$files[0]->id => 1, $files[1]->id => 2]);
});

it('keeps every imported response file with what each row said and what was done with it', function (): void {
    [$batch, $paid] = reconcileFixture('ADN3002');
    $bounced = addPendingLine($batch, 'ADN3003');
    $mismatch = addPendingLine($batch, 'ADN3004');

    $file = importResponse($batch, "ADN,Status,UTR,Amount,Reason\n"
        ."ADN3002,Success,UTR3002,1000.00,\n"
        ."ADN3003,Failed,,,Invalid IFSC\n"
        ."ADN3004,Success,UTR3004,9.99,\n"
        ."ADN9999,Success,UTR9999,,\n"
        ."ADN3002,Success,UTR3002,1000.00,\n");

    $results = $file->rows()->orderBy('row_no')->pluck('result', 'row_no')->all();

    expect($file->outcome)->toBe(PayoutBankFile::OUTCOME_APPLIED)
        ->and($file->row_count)->toBe(5)
        ->and($results)->toBe([
            1 => PayoutBankFileRow::RESULT_MARKED_PAID,
            2 => PayoutBankFileRow::RESULT_MARKED_FAILED,
            3 => PayoutBankFileRow::RESULT_REJECTED_AMOUNT,
            4 => PayoutBankFileRow::RESULT_UNMATCHED,
            5 => PayoutBankFileRow::RESULT_ALREADY_SETTLED,
        ])
        ->and($file->rows()->where('adn', 'ADN3003')->value('reason'))->toBe('Invalid IFSC')
        ->and($file->summary['transferred'])->toBe(1)
        ->and(Storage::disk(PayoutBankFile::DISK)->exists($file->storage_key))->toBeTrue();
});

it('keeps a file it could not read, applies nothing, and says why', function (): void {
    [$batch, $line] = reconcileFixture('ADN3005');

    $file = importResponse($batch, "Beneficiary,Result\nADN3005,Success\n");

    expect($file->outcome)->toBe(PayoutBankFile::OUTCOME_REFUSED)
        ->and($file->summary['errors'])->not->toBeEmpty()
        ->and($file->rows()->count())->toBe(0)
        ->and($line->fresh()->status)->toBe('pending');
});

it('labels a re-upload of the same file identical, and the comparison finds nothing', function (): void {
    [$batch] = reconcileFixture('ADN3006');
    $csv = "ADN,Status,UTR\nADN3006,Success,UTR3006\n";

    $first = importResponse($batch, $csv);
    $second = importResponse($batch, $csv);

    $comparison = app(PayoutBankFileDiffService::class)->compare($first, $second);

    expect($second->identical_to_id)->toBe($first->id)
        ->and($second->storage_key)->not->toBe($first->storage_key)
        ->and($comparison->hasDifferences())->toBeFalse()
        ->and($comparison->unchanged)->toBe(1);
});

it('compares two imports by ADN: status changed, new, missing, and duplicates flagged', function (): void {
    [$batch] = reconcileFixture('ADN3007');
    addPendingLine($batch, 'ADN3008');
    addPendingLine($batch, 'ADN3009');

    $first = importResponse($batch, "ADN,Status,UTR,Reason\nADN3007,Failed,,Timeout\nADN3008,Success,UTR3008,\n");
    $second = importResponse($batch, "ADN,Status,UTR,Reason\nADN3007,Success,UTR3007,\nADN3009,Success,UTR3009,\nADN3009,Success,UTR3009,\n");

    $comparison = app(PayoutBankFileDiffService::class)->compare($first, $second);
    $kinds = collect($comparison->changes)->mapWithKeys(fn ($c) => [$c->adn => $c->kinds])->all();

    // What moved comes first; new, then missing, after it.
    expect(array_map(fn ($c) => (string) $c->adn, $comparison->changes))->toBe(['ADN3007', 'ADN3009', 'ADN3008']);

    // Absence alone is not a difference on the side-by-side page.
    expect(array_map('strval', array_keys(app(PayoutBankFileDiffService::class)->history($batch)->rows)))->toBe(['ADN3007']);

    expect($kinds['ADN3007'])->toContain('status', 'utr', 'reason')
        ->and($kinds['ADN3008'])->toBe(['missing'])
        ->and($kinds['ADN3009'])->toBe(['new'])
        ->and($comparison->duplicates)->toBe(['ADN3009'])
        ->and($comparison->counts['status'])->toBe(1);
});

it('compares against the previous import by default and the first on request, and shows only differing ADNs side by side', function (): void {
    [$batch] = reconcileFixture('ADN3010');
    addPendingLine($batch, 'ADN3011');
    $finance = bankFileFinance();

    $one = importResponse($batch, "ADN,Status\nADN3010,Failed\nADN3011,Success\n", $finance->id);
    $two = importResponse($batch, "ADN,Status\nADN3010,Failed\nADN3011,Success\n", $finance->id);
    $three = importResponse($batch, "ADN,Status,UTR\nADN3010,Success,UTR3010\nADN3011,Success,\n", $finance->id);

    $previous = $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.bank-files.compare', $batch))->assertOk();
    expect($previous->viewData('comparison')->from->id)->toBe($two->id)
        ->and($previous->viewData('comparison')->to->id)->toBe($three->id);

    $first = $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.bank-files.compare', [$batch, 'vs' => 'first']))->assertOk();
    expect($first->viewData('comparison')->from->id)->toBe($one->id);

    $history = app(PayoutBankFileDiffService::class)->history($batch);
    expect(array_map('strval', array_keys($history->rows)))->toBe(['ADN3010'])
        ->and($history->totalAdns)->toBe(2)
        ->and(array_keys(app(PayoutBankFileDiffService::class)->history($batch, all: true)->rows))->toHaveCount(2);

    $this->actingAs($finance)->get(route('admin.compensation.weekly-payouts.bank-files.history', $batch))
        ->assertOk()
        ->assertSee('ADN3010');
});

it('downloads a stored file for finance only, audited, and not across batches', function (): void {
    [$batch] = reconcileFixture('ADN3012');
    [$otherBatch] = reconcileFixture('ADN3013', 3);
    $finance = bankFileFinance();
    $csv = "ADN,Status\nADN3012,Success\n";
    $file = importResponse($batch, $csv, $finance->id);

    $body = $this->actingAs($finance)
        ->get(route('admin.compensation.weekly-payouts.bank-files.download', [$batch, $file]))
        ->assertOk()
        ->streamedContent();

    expect($body)->toBe($csv)
        ->and(DB::table('audit_log')->where('action', 'payout.bank_file.downloaded')->count())->toBe(1);

    $this->actingAs($finance)
        ->get(route('admin.compensation.weekly-payouts.bank-files.download', [$otherBatch, $file]))
        ->assertNotFound();

    $compliance = User::factory()->create(['status' => 'active']);
    $compliance->assignRole('admin-compliance');
    $this->actingAs($compliance)
        ->get(route('admin.compensation.weekly-payouts.bank-files.download', [$batch, $file]))
        ->assertForbidden();
});

it('refuses the export and the import when storage is down, leaving no record behind', function (): void {
    [$batch, $line] = reconcileFixture('ADN3014');
    $finance = bankFileFinance();

    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('put')->andThrow(new RuntimeException('S3 unavailable'));
    Storage::set(PayoutBankFile::DISK, $broken);

    $this->actingAs($finance)
        ->from(route('admin.compensation.weekly-payouts.show', $batch))
        ->get(route('admin.compensation.weekly-payouts.neft', $batch))
        ->assertSessionHas('error');

    $summary = app(PayoutReconciliationService::class)->import($batch, uploadCsv("ADN,Status\nADN3014,Success\n"), $finance->id);

    expect($summary['errors'])->not->toBeEmpty()
        ->and(PayoutBankFile::count())->toBe(0)
        ->and($line->fresh()->status)->toBe('pending');
});

it('deletes the stored object when its record cannot be saved', function (): void {
    [$batch] = reconcileFixture('ADN3015');
    $vault = app(PayoutBankFileVault::class);

    // The row insert fails after the object is already written.
    PayoutBankFile::creating(function (): void {
        throw new RuntimeException('database unavailable');
    });

    expect(fn () => $vault->keep($batch, PayoutBankFile::DIRECTION_IMPORT, 'bytes', 'f.csv', null))
        ->toThrow(RuntimeException::class, 'database unavailable');

    expect(Storage::disk(PayoutBankFile::DISK)->allFiles())->toBe([]);
});

it('purges only expired files, keeps their rows, and says so on download', function (): void {
    [$batch] = reconcileFixture('ADN3016');
    $finance = bankFileFinance();
    $old = importResponse($batch, "ADN,Status\nADN3016,Failed\n", $finance->id);
    $new = importResponse($batch, "ADN,Status\nADN3016,Success\n", $finance->id);
    $old->forceFill(['created_at' => now()->subDays(3000)])->save();
    $oldKey = $old->storage_key;

    Artisan::call('payout:purge-expired-bank-files');

    expect($old->fresh()->storage_key)->toBeNull()
        ->and($old->fresh()->purged_at)->not->toBeNull()
        ->and(Storage::disk(PayoutBankFile::DISK)->exists($oldKey))->toBeFalse()
        ->and($new->fresh()->storage_key)->not->toBeNull()
        ->and($old->rows()->count())->toBe(1)
        ->and(app(PayoutBankFileDiffService::class)->compare($old->fresh(), $new)->hasDifferences())->toBeTrue()
        ->and(DB::table('audit_log')->where('action', 'payout.bank_file.purged')->count())->toBe(1);

    $this->actingAs($finance)
        ->from(route('admin.compensation.weekly-payouts.show', $batch))
        ->get(route('admin.compensation.weekly-payouts.bank-files.download', [$batch, $old]))
        ->assertSessionHas('error');
});

it('registers the purge command on the daily schedule', function (): void {
    expect(Artisan::all())->toHaveKey('payout:purge-expired-bank-files');

    $scheduled = collect(app(Schedule::class)->events())
        ->contains(fn ($event) => str_contains((string) $event->command, 'payout:purge-expired-bank-files'));

    expect($scheduled)->toBeTrue();
});

/** Another pending line on the batch for a new distributor. */
function addPendingLine(PayoutBatch $batch, string $adn): PayoutLineItem
{
    return PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => Distributor::factory()->create(['adn' => $adn])->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 100_000,
        'status' => 'pending',
    ]);
}
