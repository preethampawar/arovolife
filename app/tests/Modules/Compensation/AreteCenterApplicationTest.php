<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Compensation\Models\AreteCenterApplicationDocument;
use App\Modules\Compensation\Notifications\AreteCenterApplicationReviewedNotification;
use App\Modules\Compensation\Notifications\AreteCenterApplicationSubmittedNotification;
use App\Modules\Compensation\Notifications\AreteCenterDeactivatedNotification;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);
    Storage::fake('adc');
    Notification::fake();
});

function adcAppAdmin(): User
{
    $user = User::create([
        'full_name' => 'ADC Reviewer',
        'email' => 'adc-reviewer-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin-compliance');

    return $user;
}

/** A fake upload whose bytes pass the magic-number check as a PDF. */
function adcAppPdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, '%PDF-1.4\n'.str_repeat('x', 512));
}

/** The five named site photos, one small JPEG each. @return array<string, list<UploadedFile>> */
function adcAppPhotos(): array
{
    $out = [];
    foreach (AreteCenterApplicationDocument::photoTypes() as $type) {
        $out[$type] = [UploadedFile::fake()->image("{$type}.jpg", 200, 150)];
    }

    return $out;
}

/** @return array<string, mixed> */
function adcAppPayload(array $overrides = []): array
{
    return array_merge([
        'centre_name' => 'Sangareddy Arete Development Centre',
        'address_line_1' => '12 Bank Colony',
        'landmark' => 'Opposite bus stand',
        'pincode' => '502001',
        'city' => 'Sangareddy',
        'state' => 'Telangana',
        'property_type' => 'commercial',
        'premises_sqft' => 450,
        'distance_to_nearest_adc_km' => '12.5',
        'opening_time' => '10:00',
        'closing_time' => '19:00',
        'weekly_off' => 'sunday',
        'declarations' => AreteCenterDeclarations::keys(),
        'documents' => [
            'ownership_or_rent_proof' => [adcAppPdf('rent.pdf')],
            'address_proof' => [adcAppPdf('bill.pdf')],
            ...adcAppPhotos(),
        ],
    ], $overrides);
}

function adcAppSubmit(Distributor $distributor, array $overrides = []): AreteCenterApplication
{
    test()->actingAs($distributor->user)
        ->post(route('my.adc.apply.submit'), adcAppPayload($overrides))
        ->assertRedirect(route('my.adc.status'));

    return AreteCenterApplication::where('distributor_id', $distributor->id)->latest('id')->firstOrFail();
}

test('flag off leaves no trace: apply page and admin queue 404', function (): void {
    Feature::for(null)->deactivate(AreteCenterApplicationsFeature::class);
    $distributor = Distributor::factory()->create();

    $this->actingAs($distributor->user)->get(route('my.adc.apply'))->assertNotFound();
    $this->actingAs(adcAppAdmin())->get(route('admin.arete-centres.applications.index'))->assertNotFound();
});

test('distributor can submit an application with documents and declarations', function (): void {
    $distributor = Distributor::factory()->create();

    $application = adcAppSubmit($distributor);

    expect($application->status)->toBe(AreteCenterApplication::STATUS_SUBMITTED)
        ->and($application->premises_sqft)->toBe(450)
        ->and($application->documents()->count())->toBe(7)
        ->and($application->declarations()->count())->toBe(count(AreteCenterDeclarations::keys()));

    foreach ($application->documents as $document) {
        Storage::disk('adc')->assertExists($document->object_storage_key);
        expect($document->object_storage_key)->toStartWith("application_{$application->id}/");
    }

    expect(AuditLog::where('action', 'adc.application.submitted')->where('subject_id', $application->id)->exists())->toBeTrue();
    Notification::assertSentTo($distributor->user, AreteCenterApplicationSubmittedNotification::class);
});

test('premises below the admin-configured minimum and a missing declaration are rejected', function (): void {
    DB::table('settings')->insert(['key' => 'adc.min_premises_sqft', 'value' => '500', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $distributor = Distributor::factory()->create();

    $this->actingAs($distributor->user)->get(route('my.adc.apply'))->assertOk()->assertSee('value="500"', false);

    $this->actingAs($distributor->user)
        ->post(route('my.adc.apply.submit'), adcAppPayload(['premises_sqft' => 450]))
        ->assertSessionHasErrors('premises_sqft');

    $this->actingAs($distributor->user)
        ->post(route('my.adc.apply.submit'), adcAppPayload(['premises_sqft' => 600, 'declarations' => ['details_true']]))
        ->assertSessionHasErrors();

    expect(AreteCenterApplication::count())->toBe(0);
});

test('a second application cannot be opened while one is in progress', function (): void {
    $distributor = Distributor::factory()->create();
    adcAppSubmit($distributor);

    $this->actingAs($distributor->user)->get(route('my.adc.apply'))->assertRedirect(route('my.adc.status'));
    $this->actingAs($distributor->user)->post(route('my.adc.apply.submit'), adcAppPayload())->assertSessionHasErrors('application');

    expect(AreteCenterApplication::count())->toBe(1);
});

test('approval creates an active distributor centre at phase 1 owned by the applicant', function (): void {
    $distributor = Distributor::factory()->create();
    $application = adcAppSubmit($distributor);
    $admin = adcAppAdmin();

    $this->actingAs($admin)
        ->post(route('admin.arete-centres.applications.review', [$application, 'approve']), ['reason' => 'Looks good'])
        ->assertRedirect(route('admin.arete-centres.applications.show', $application));

    $application->refresh();
    $center = $application->center;

    expect($application->status)->toBe(AreteCenterApplication::STATUS_APPROVED)
        ->and($center)->not->toBeNull()
        ->and($center->status)->toBe(AreteCenter::STATUS_ACTIVE)
        ->and($center->centre_type)->toBe(AreteCenter::TYPE_DISTRIBUTOR)
        ->and($center->development_phase)->toBe(1)
        ->and($center->assigned_distributor_id)->toBe($distributor->id)
        ->and($center->city)->toBe('Sangareddy')
        ->and($center->state)->toBe('Telangana')
        ->and($center->premises_sqft)->toBe(450);

    expect(AuditLog::where('action', 'adc.application.approved')->where('subject_id', $application->id)->exists())->toBeTrue();
    Notification::assertSentTo($distributor->user, AreteCenterApplicationReviewedNotification::class,
        fn ($n) => $n->status === AreteCenterApplication::STATUS_APPROVED);

    // Approving again is refused — the application is closed.
    $this->actingAs($admin)
        ->post(route('admin.arete-centres.applications.review', [$application, 'approve']))
        ->assertSessionHasErrors('review');
});

test('request changes lets the applicant edit and resubmit; reject needs a reason', function (): void {
    $distributor = Distributor::factory()->create();
    $application = adcAppSubmit($distributor);
    $admin = adcAppAdmin();

    $this->actingAs($admin)
        ->post(route('admin.arete-centres.applications.review', [$application, 'request-changes']), [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)
        ->post(route('admin.arete-centres.applications.review', [$application, 'request-changes']), ['reason' => 'Upload a clearer rent agreement'])
        ->assertSessionHasNoErrors();

    expect($application->refresh()->status)->toBe(AreteCenterApplication::STATUS_NEEDS_CHANGES);
    $this->actingAs($distributor->user)->get(route('my.adc.edit'))->assertOk()->assertSee('Upload a clearer rent agreement');

    // Resubmit with a replacement rent proof only — the other documents stay.
    $this->actingAs($distributor->user)
        ->put(route('my.adc.update'), adcAppPayload([
            'centre_name' => 'Sangareddy ADC (revised)',
            'documents' => ['ownership_or_rent_proof' => [adcAppPdf('rent-v2.pdf')]],
        ]))
        ->assertRedirect(route('my.adc.status'));

    $application->refresh();
    expect($application->status)->toBe(AreteCenterApplication::STATUS_SUBMITTED)
        ->and($application->centre_name)->toBe('Sangareddy ADC (revised)')
        ->and($application->documents()->count())->toBe(7)
        ->and($application->documents()->where('type', 'ownership_or_rent_proof')->value('original_name'))->toBe('rent-v2.pdf');

    $this->actingAs($admin)
        ->post(route('admin.arete-centres.applications.review', [$application, 'reject']), ['reason' => 'Premises not suitable'])
        ->assertSessionHasNoErrors();

    expect($application->refresh()->status)->toBe(AreteCenterApplication::STATUS_REJECTED);
    Notification::assertSentTo($distributor->user, AreteCenterApplicationReviewedNotification::class,
        fn ($n) => $n->status === AreteCenterApplication::STATUS_REJECTED && $n->reason === 'Premises not suitable');

    // A rejected applicant may apply afresh.
    $this->actingAs($distributor->user)->get(route('my.adc.apply'))->assertOk();
});

test('admin registry filters by state and type, and deactivation hides the centre from step 11', function (): void {
    $owner = Distributor::factory()->create();
    AreteCenter::create(['name' => 'Company HQ', 'centre_type' => 'company', 'city' => 'Hyderabad', 'state' => 'Telangana', 'status' => 'active', 'is_company_default' => true]);
    $pune = AreteCenter::create(['name' => 'Pune Centre', 'centre_type' => 'distributor', 'city' => 'Pune', 'state' => 'Maharashtra', 'assigned_distributor_id' => $owner->id, 'status' => 'active']);
    $admin = adcAppAdmin();

    $this->actingAs($admin)->get(route('admin.arete-centres.index', ['state' => 'Maharashtra']))
        ->assertOk()->assertSee('Pune Centre')->assertDontSee('Company HQ');
    $this->actingAs($admin)->get(route('admin.arete-centres.index', ['type' => 'company']))
        ->assertOk()->assertSee('Company HQ')->assertDontSee('Pune Centre');

    $this->actingAs($admin)
        ->post(route('admin.arete-centres.status', [$pune, 'deactivate']), [])
        ->assertSessionHasErrors('reason');
    $this->actingAs($admin)
        ->post(route('admin.arete-centres.status', [$pune, 'deactivate']), ['reason' => 'Premises closed'])
        ->assertSessionHasNoErrors();

    expect($pune->refresh()->status)->toBe(AreteCenter::STATUS_INACTIVE)
        ->and($pune->deactivation_reason)->toBe('Premises closed')
        ->and(AreteCenter::query()->selectable()->pluck('name')->all())->toBe(['Company HQ']);

    $this->actingAs($admin)
        ->post(route('admin.arete-centres.status', [$pune, 'activate']))
        ->assertSessionHasNoErrors();
    expect(AreteCenter::query()->selectable()->pluck('name')->all())->toBe(['Pune Centre', 'Company HQ']);
});

test('admin-finance cannot open the queue, view documents, decide, or deactivate a centre', function (): void {
    $distributor = Distributor::factory()->create();
    $application = adcAppSubmit($distributor);
    $document = $application->documents()->firstOrFail();
    $center = AreteCenter::create(['name' => 'Owned Centre', 'centre_type' => 'distributor', 'assigned_distributor_id' => $distributor->id, 'status' => 'active']);

    $finance = User::create([
        'full_name' => 'Finance Clerk',
        'email' => 'adc-finance-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $finance->assignRole('admin-finance');

    $this->actingAs($finance)->get(route('admin.arete-centres.applications.index'))->assertForbidden();
    $this->actingAs($finance)->get(route('admin.arete-centres.applications.show', $application))->assertForbidden();
    $this->actingAs($finance)->get(route('admin.arete-centres.applications.document', [$application, $document]))->assertForbidden();
    $this->actingAs($finance)->post(route('admin.arete-centres.applications.review', [$application, 'approve']))->assertForbidden();
    $this->actingAs($finance)->post(route('admin.arete-centres.status', [$center, 'deactivate']), ['reason' => 'x'])->assertForbidden();

    expect($application->refresh()->status)->toBe(AreteCenterApplication::STATUS_SUBMITTED)
        ->and($center->refresh()->status)->toBe(AreteCenter::STATUS_ACTIVE);
});

test('viewing a document is audit-logged and deactivation emails the owner', function (): void {
    $distributor = Distributor::factory()->create();
    $application = adcAppSubmit($distributor);
    $document = $application->documents()->firstOrFail();
    $admin = adcAppAdmin();

    $this->actingAs($admin)
        ->get(route('admin.arete-centres.applications.document', [$application, $document]))
        ->assertOk();

    expect(AuditLog::where('action', 'adc.application.document_viewed')->where('subject_id', $application->id)->exists())->toBeTrue();

    $center = AreteCenter::create(['name' => 'Owned Centre', 'centre_type' => 'distributor', 'assigned_distributor_id' => $distributor->id, 'status' => 'active']);
    $this->actingAs($admin)
        ->post(route('admin.arete-centres.status', [$center, 'deactivate']), ['reason' => 'Premises closed'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($distributor->user, AreteCenterDeactivatedNotification::class,
        fn ($n) => $n->reason === 'Premises closed');
});

test('rejected applications lose their documents after the retention window', function (): void {
    $distributor = Distributor::factory()->create();
    $application = adcAppSubmit($distributor);
    $keys = $application->documents()->pluck('object_storage_key')->all();

    $this->actingAs(adcAppAdmin())
        ->post(route('admin.arete-centres.applications.review', [$application, 'reject']), ['reason' => 'No'])
        ->assertSessionHasNoErrors();

    $this->artisan('adc:purge-rejected-documents')->assertSuccessful();
    expect($application->documents()->count())->toBe(7);

    $application->forceFill(['reviewed_at' => now()->subDays(91)])->save();
    $this->artisan('adc:purge-rejected-documents')->assertSuccessful();

    expect($application->documents()->count())->toBe(0)
        ->and(AreteCenterApplication::find($application->id))->not->toBeNull();
    foreach ($keys as $key) {
        Storage::disk('adc')->assertMissing($key);
    }
    expect(AuditLog::where('action', 'adc.application.documents_purged')->where('subject_id', $application->id)->exists())->toBeTrue();
});

test('site photos are one image per named slot, capped by the admin photo-size setting', function (): void {
    DB::table('settings')->insert(['key' => 'adc.max_photo_kb', 'value' => '100', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $distributor = Distributor::factory()->create();

    // A PDF is not a photo.
    $this->actingAs($distributor->user)
        ->post(route('my.adc.apply.submit'), adcAppPayload(['documents' => [
            'ownership_or_rent_proof' => [adcAppPdf('rent.pdf')],
            'address_proof' => [adcAppPdf('bill.pdf')],
            ...adcAppPhotos(),
            'photo_front' => [adcAppPdf('front.pdf')],
        ]]))
        ->assertSessionHasErrors('documents.photo_front.0');

    // Over the cap.
    $this->actingAs($distributor->user)
        ->post(route('my.adc.apply.submit'), adcAppPayload(['documents' => [
            'ownership_or_rent_proof' => [adcAppPdf('rent.pdf')],
            'address_proof' => [adcAppPdf('bill.pdf')],
            ...adcAppPhotos(),
            'photo_left' => [UploadedFile::fake()->image('left.jpg', 200, 150)->size(150)],
        ]]))
        ->assertSessionHasErrors('documents.photo_left.0');

    // A missing slot.
    $photos = adcAppPhotos();
    unset($photos['photo_approach']);
    $this->actingAs($distributor->user)
        ->post(route('my.adc.apply.submit'), adcAppPayload(['documents' => [
            'ownership_or_rent_proof' => [adcAppPdf('rent.pdf')],
            'address_proof' => [adcAppPdf('bill.pdf')],
            ...$photos,
        ]]))
        ->assertSessionHasErrors('documents.photo_approach');

    expect(AreteCenterApplication::count())->toBe(0);
});

test('the apply page shows PAN and bank masked with a reveal toggle, and stores the applicant alternate mobile', function (): void {
    $distributor = Distributor::factory()->create(['pan_last4' => '234F', 'bank_ifsc' => 'HDFC0001234']);

    $this->actingAs($distributor->user)->get(route('my.adc.apply'))
        ->assertOk()
        ->assertSee('XXXXXX234F')
        ->assertSee('HDFC0001234')
        ->assertSee('id="identityRevealToggle"', false)
        ->assertSee('applicant_alternate_mobile');

    $application = adcAppSubmit($distributor, ['applicant_alternate_mobile' => '+919876501234']);

    expect($application->applicant_alternate_mobile)->toBe('+919876501234');

    $this->actingAs(adcAppAdmin())
        ->get(route('admin.arete-centres.applications.show', $application))
        ->assertOk()
        ->assertSee('+919876501234')
        ->assertSee('Photo — approach to the location');
});

test('the centre directory is members-only, filters by state and city, and never shows the owner ADN', function (): void {
    $owner = Distributor::factory()->create();
    AreteCenter::create(['name' => 'Company HQ', 'centre_type' => 'company', 'city' => 'Hyderabad', 'state' => 'Telangana', 'status' => 'active', 'is_company_default' => true, 'contact_person' => 'Front desk', 'contact_number' => '+914012345678', 'weekly_off' => 'sunday', 'address_line_1' => '1 Banjara Hills']);
    AreteCenter::create(['name' => 'Pune Centre', 'centre_type' => 'distributor', 'city' => 'Pune', 'state' => 'Maharashtra', 'assigned_distributor_id' => $owner->id, 'status' => 'active', 'contact_number' => '+919999900000']);
    AreteCenter::create(['name' => 'Closed Centre', 'centre_type' => 'company', 'city' => 'Nagpur', 'state' => 'Maharashtra', 'status' => 'inactive']);

    $this->get(route('my.adc.directory'))->assertRedirect(route('login'));

    $member = Distributor::factory()->create();
    $this->actingAs($member->user)->get(route('my.adc.directory'))
        ->assertOk()
        ->assertSee('Company HQ')->assertSee('Front desk')->assertSee('+914012345678')->assertSee('Sunday')
        ->assertSee('Pune Centre')
        ->assertDontSee('Closed Centre')
        ->assertDontSee($owner->adn);

    $this->actingAs($member->user)->get(route('my.adc.directory', ['state' => 'Maharashtra']))
        ->assertOk()->assertSee('+919999900000')->assertDontSee('Front desk'); // HQ stays in the centre dropdown, not the list

    $this->actingAs($member->user)->get(route('my.adc.directory', ['state' => 'Maharashtra', 'city' => 'Hyderabad']))
        ->assertOk()->assertSee('No centres match');
});

test('the registry and the application queue live under Arete Centres and do not depend on the ADC bonus flag', function (): void {
    Feature::for(null)->deactivate(AreteDevelopmentCenterBonusFeature::class);
    AreteCenter::create(['name' => 'Company HQ', 'centre_type' => 'company', 'city' => 'Hyderabad', 'state' => 'Telangana', 'status' => 'active', 'is_company_default' => true]);
    $admin = adcAppAdmin();

    $this->actingAs($admin)->get(route('admin.arete-centres.index'))
        ->assertOk()->assertSee('Company HQ')->assertSee(route('admin.arete-centres.applications.index'), false);
    $this->actingAs($admin)->get(route('admin.arete-centres.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.arete-centres.applications.index'))->assertOk();

    // Sidebar entry is there for any admin, whatever the bonus flag says; the
    // bonus report itself stays hidden while its own flag is off.
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee(route('admin.arete-centres.index'), false);
    $this->actingAs($admin)->get(route('admin.compensation.adc-bonus.index'))->assertNotFound();
});

test('the Applications tab and sidebar badge disappear while the applications flag is off', function (): void {
    Feature::for(null)->deactivate(AreteCenterApplicationsFeature::class);
    $admin = adcAppAdmin();

    $this->actingAs($admin)->get(route('admin.arete-centres.index'))
        ->assertOk()->assertDontSee(route('admin.arete-centres.applications.index'), false);
});

test('legacy ADC bonus URLs for the registry and the queue redirect to Arete Centres', function (): void {
    $admin = adcAppAdmin();

    $this->actingAs($admin)->get('/admin/compensation/adc-bonus/centers')
        ->assertRedirect(url('/admin/arete-centres'))->assertStatus(301);
    $this->actingAs($admin)->get('/admin/compensation/adc-bonus/centers/create')
        ->assertRedirect(url('/admin/arete-centres/create'))->assertStatus(301);
    $this->actingAs($admin)->get('/admin/compensation/adc-bonus/applications')
        ->assertRedirect(url('/admin/arete-centres/applications'))->assertStatus(301);
});

test('admin staff can open the members-only centre directory', function (): void {
    AreteCenter::create(['name' => 'Company HQ', 'centre_type' => 'company', 'city' => 'Hyderabad', 'state' => 'Telangana', 'status' => 'active', 'is_company_default' => true]);

    $this->actingAs(adcAppAdmin())->get(route('my.adc.directory'))->assertOk()->assertSee('Company HQ');
    $this->actingAs(adcAppAdmin())->get(route('admin.arete-centres.index'))->assertSee(route('my.adc.directory'), false);
});
