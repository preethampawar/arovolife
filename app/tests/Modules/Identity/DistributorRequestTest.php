<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\DistributorRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\DistributorRequestDecidedNotification;
use App\Modules\Identity\Notifications\DistributorRequestSubmittedNotification;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(DistributorRequestsFeature::class);
    Storage::fake('distributor-requests');
    Notification::fake();
});

function drStaff(string $role): User
{
    $user = User::create([
        'full_name' => 'Staff '.$role,
        'email' => 'dr-'.$role.'-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function drPdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, '%PDF-1.4\n'.str_repeat('x', 512));
}

test('flag off leaves no trace: distributor pages and admin queue 404', function (): void {
    Feature::for(null)->deactivate(DistributorRequestsFeature::class);
    $distributor = Distributor::factory()->create();

    $this->actingAs($distributor->user)->get(route('my.requests.index'))->assertNotFound();
    $this->actingAs(drStaff('admin-operations'))->get(route('admin.distributor-requests.index'))->assertNotFound();
});

test('a name correction is filed with its document, then approved by operations and applied to the record', function (): void {
    $distributor = Distributor::factory()->create();
    $distributor->user->forceFill(['full_name' => 'Ravi Kumar'])->save();

    $this->actingAs($distributor->user)->get(route('my.requests.create', ['type' => 'name_correction']))
        ->assertOk()->assertSee('Name as it should appear');

    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'name_correction',
        'requested_full_name' => 'Ravi  Kumar Reddy',
        'reason' => 'My surname is missing.',
        'documents' => ['id_proof' => [drPdf('pan.pdf')]],
    ])->assertRedirect(route('my.requests.index'));

    $request = DistributorRequest::firstOrFail();
    expect($request->status)->toBe('submitted')
        ->and($request->details['requested_full_name'])->toBe('Ravi Kumar Reddy')
        ->and($request->snapshot_before['full_name'])->toBe('Ravi Kumar')
        ->and($request->request_no)->toStartWith('DR-')
        ->and($request->documents()->count())->toBe(1);
    Storage::disk('distributor-requests')->assertExists($request->documents()->first()->object_storage_key);
    Notification::assertSentTo($distributor->user, DistributorRequestSubmittedNotification::class);

    // A second open request of the same type is refused; a different type is fine.
    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'name_correction', 'requested_full_name' => 'X Y', 'reason' => 'again',
        'documents' => ['id_proof' => [drPdf('pan.pdf')]],
    ])->assertSessionHasErrors('request');

    // Compliance can read but not decide a name request; operations decides it.
    $compliance = drStaff('admin-compliance');
    $this->actingAs($compliance)->get(route('admin.distributor-requests.show', $request))->assertOk()->assertSee('Ravi Kumar Reddy');
    $this->actingAs($compliance)->post(route('admin.distributor-requests.decide', [$request, 'approve']))->assertForbidden();

    $ops = drStaff('admin-operations');
    $this->actingAs($ops)->post(route('admin.distributor-requests.decide', [$request, 'review']))->assertSessionHasNoErrors();
    $this->actingAs($ops)->post(route('admin.distributor-requests.decide', [$request, 'approve']), ['reason' => 'Matches PAN'])->assertSessionHasNoErrors();

    $request->refresh();
    expect($request->status)->toBe('approved')
        ->and($request->applied_at)->not->toBeNull()
        ->and($distributor->user->fresh()->full_name)->toBe('Ravi Kumar Reddy')
        ->and(AuditLog::where('action', 'profile.identity_corrected')->where('subject_id', $distributor->user_id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'distributor_request.approved')->where('subject_id', $request->id)->exists())->toBeTrue();
    Notification::assertSentTo($distributor->user, DistributorRequestDecidedNotification::class, fn ($n) => $n->applied === true);
});

test('a DOB correction must be 18+, and rejection needs a reason and emails it', function (): void {
    $distributor = Distributor::factory()->create();

    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'dob_correction', 'requested_date_of_birth' => now()->subYears(10)->toDateString(), 'reason' => 'typo',
        'documents' => ['id_proof' => [drPdf('pan.pdf')]],
    ])->assertSessionHasErrors('requested_date_of_birth');

    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'dob_correction', 'requested_date_of_birth' => '1985-03-04', 'reason' => 'typo',
    ])->assertSessionHasErrors('documents.id_proof');

    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'dob_correction', 'requested_date_of_birth' => '1985-03-04', 'reason' => 'typo',
        'documents' => ['id_proof' => [drPdf('pan.pdf')]],
    ])->assertSessionHasNoErrors();

    $request = DistributorRequest::firstOrFail();
    $ops = drStaff('admin-operations');
    $this->actingAs($ops)->post(route('admin.distributor-requests.decide', [$request, 'reject']))->assertSessionHasErrors('reason');
    $this->actingAs($ops)->post(route('admin.distributor-requests.decide', [$request, 'reject']), ['reason' => 'Document unreadable'])->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->applied_at)->toBeNull();
    Notification::assertSentTo($distributor->user, DistributorRequestDecidedNotification::class, fn ($n) => $n->status === 'rejected' && $n->note === 'Document unreadable');

    // The distributor sees the reason on their own request page, and nobody else's.
    $this->actingAs($distributor->user)->get(route('my.requests.show', $request))->assertOk()->assertSee('Document unreadable');
    $other = Distributor::factory()->create();
    $this->actingAs($other->user)->get(route('my.requests.show', $request))->assertNotFound();
});

test('transfer and cancellation are decided by compliance and approval only acknowledges', function (): void {
    $distributor = Distributor::factory()->create();
    $nameBefore = $distributor->user->full_name;

    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'membership_transfer', 'transferee_name' => 'Sita Kumar', 'relationship' => 'spouse',
        'transferee_mobile' => '+919876500001', 'reason' => 'Moving abroad',
        'documents' => ['relationship_proof' => [drPdf('marriage.pdf')], 'consent_letter' => [drPdf('consent.pdf')]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'id_cancellation', 'reason' => 'No longer active',
    ])->assertSessionHasErrors('acknowledged');
    $this->actingAs($distributor->user)->post(route('my.requests.store'), [
        'type' => 'id_cancellation', 'reason' => 'No longer active', 'acknowledged' => '1',
    ])->assertSessionHasNoErrors();

    expect(DistributorRequest::count())->toBe(2);
    $transfer = DistributorRequest::where('type', 'membership_transfer')->firstOrFail();

    $ops = drStaff('admin-operations');
    $this->actingAs($ops)->post(route('admin.distributor-requests.decide', [$transfer, 'approve']))->assertForbidden();

    $compliance = drStaff('admin-compliance');
    $this->actingAs($compliance)->post(route('admin.distributor-requests.decide', [$transfer, 'approve']), ['reason' => 'Accepted in principle'])->assertSessionHasNoErrors();

    expect($transfer->fresh()->status)->toBe('approved')
        ->and($transfer->fresh()->applied_at)->toBeNull()
        ->and($distributor->user->fresh()->full_name)->toBe($nameBefore)
        ->and(AuditLog::where('action', 'profile.identity_corrected')->count())->toBe(0);
    Notification::assertSentTo($distributor->user, DistributorRequestDecidedNotification::class, fn ($n) => $n->applied === false);

    // Finance cannot open the queue; document viewing is audit-logged for those who can.
    $this->actingAs(drStaff('admin-finance'))->get(route('admin.distributor-requests.index'))->assertForbidden();
    $doc = $transfer->documents()->firstOrFail();
    $this->actingAs($compliance)->get(route('admin.distributor-requests.document', [$transfer, $doc]))->assertOk();
    expect(AuditLog::where('action', 'distributor_request.document_viewed')->where('subject_id', $transfer->id)->exists())->toBeTrue();
});
