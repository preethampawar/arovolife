<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);
});

function adcReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Adc Report Admin',
        'email' => 'adc-report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function adcReportDistributor(string $adn, string $name): Distributor
{
    $distributor = Distributor::factory()->create(['adn' => $adn]);
    $distributor->user()->update(['full_name' => $name]);

    return $distributor;
}

/**
 * @param  array{pincode?: ?string, district?: ?string, state?: ?string, location?: ?string}  $address
 */
function adcReportCenterWithResult(string $centerName, string $adn, string $name, array $address = []): AdcBonusResult
{
    $distributor = adcReportDistributor($adn, $name);

    $center = AreteCenter::create([
        'name' => $centerName,
        'location' => $address['location'] ?? null,
        'pincode' => $address['pincode'] ?? null,
        'district' => $address['district'] ?? null,
        'state' => $address['state'] ?? null,
        'assigned_distributor_id' => $distributor->id,
        'status' => AreteCenter::STATUS_ACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);

    return AdcBonusResult::create([
        'center_id' => $center->id,
        'distributor_id' => $distributor->id,
        'month_start' => '2026-07-01',
        'member_count' => 3,
        'total_member_bv_paise' => 1_000_000,
        'gross_paise' => 30_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => 30_000,
        'status' => AdcBonusResult::STATUS_CREDITED,
    ]);
}

it('renders the centre name and the pincode / district / state columns', function (): void {
    adcReportCenterWithResult('Medak Center', 'ADCAAA', 'Alice', [
        'pincode' => '502001', 'district' => 'Medak', 'state' => 'Telangana',
    ]);

    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-calculation.index'))
        ->assertOk()
        ->assertSee('Arete Center')
        ->assertSee('Pincode / District / State')
        ->assertSee('Medak Center')
        ->assertSee('ADCAAA')
        ->assertSee('502001 · Medak · Telangana', false);
});

it('falls back to the legacy location when no structured address is set', function (): void {
    adcReportCenterWithResult('Legacy Center', 'ADCLEG', 'Leela', ['location' => 'Old Town Road']);

    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-calculation.index'))
        ->assertOk()
        ->assertSee('Old Town Road');
});

it('filters the report by pincode, district and state', function (): void {
    adcReportCenterWithResult('Medak Center', 'ADCAAA', 'Alice', [
        'pincode' => '502001', 'district' => 'Medak', 'state' => 'Telangana',
    ]);
    adcReportCenterWithResult('Kochi Center', 'ADCBBB', 'Bhanu', [
        'pincode' => '682001', 'district' => 'Ernakulam', 'state' => 'Kerala',
    ]);

    $admin = adcReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.adc-calculation.index', ['area' => '502001']))
        ->assertOk()
        ->assertSee('ADCAAA')
        ->assertDontSee('ADCBBB');

    $this->actingAs($admin)
        ->get(route('admin.compensation.adc-calculation.index', ['area' => 'Ernakulam']))
        ->assertOk()
        ->assertSee('ADCBBB')
        ->assertDontSee('ADCAAA');

    $this->actingAs($admin)
        ->get(route('admin.compensation.adc-calculation.index', ['area' => 'Telangana']))
        ->assertOk()
        ->assertSee('ADCAAA')
        ->assertDontSee('ADCBBB');
});

it('matches a pincode prefix so an area can be swept', function (): void {
    adcReportCenterWithResult('Medak Center', 'ADCAAA', 'Alice', [
        'pincode' => '502001', 'district' => 'Medak', 'state' => 'Telangana',
    ]);
    adcReportCenterWithResult('Kochi Center', 'ADCBBB', 'Bhanu', [
        'pincode' => '682001', 'district' => 'Ernakulam', 'state' => 'Kerala',
    ]);

    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-calculation.index', ['area' => '502']))
        ->assertOk()
        ->assertSee('ADCAAA')
        ->assertDontSee('ADCBBB');
});

it('exports the centre and address columns to CSV', function (): void {
    adcReportCenterWithResult('Medak Center', 'ADCAAA', 'Alice', [
        'pincode' => '502001', 'district' => 'Medak', 'state' => 'Telangana',
    ]);

    $res = $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-calculation.export'));

    $res->assertOk();
    $csv = $res->getContent();
    expect($csv)->toContain('SNo,ADN,Arete Center,Name,');
    expect($csv)->toContain('Status,Location,Pincode,District,State');
    expect($csv)->toContain('"Medak Center"');
    // Ungrouped plain numbers in CSV — the address parts stay separate columns.
    expect($csv)->toContain('"502001","Medak","Telangana"');
});

it('stores a centre with its pincode, district and state', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');

    $this->actingAs(adcReportAdmin())
        ->post(route('admin.compensation.adc-bonus.centers.store'), [
            'name' => 'Medak Center',
            'location' => 'Main Road',
            'pincode' => '502001',
            'district' => 'Medak',
            'state' => 'Telangana',
            'assigned_adn' => $assignee->adn,
        ])
        ->assertRedirect(route('admin.compensation.adc-bonus.centers.index'));

    $center = AreteCenter::where('name', 'Medak Center')->firstOrFail();
    expect($center->pincode)->toBe('502001')
        ->and($center->district)->toBe('Medak')
        ->and($center->state)->toBe('Telangana');
});

it('rejects a pincode that is not exactly six digits', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');

    $this->actingAs(adcReportAdmin())
        ->post(route('admin.compensation.adc-bonus.centers.store'), [
            'name' => 'Bad Pincode Center',
            'pincode' => '50200',
            'assigned_adn' => $assignee->adn,
        ])
        ->assertSessionHasErrors('pincode');

    expect(AreteCenter::where('name', 'Bad Pincode Center')->exists())->toBeFalse();
});

it('rejects a state outside the official list of states and union territories', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');

    $this->actingAs(adcReportAdmin())
        ->post(route('admin.compensation.adc-bonus.centers.store'), [
            'name' => 'Atlantis Center',
            'state' => 'Atlantis',
            'assigned_adn' => $assignee->adn,
        ])
        ->assertSessionHasErrors('state');

    expect(AreteCenter::where('name', 'Atlantis Center')->exists())->toBeFalse();
});

it('offers every state and union territory on the centre form', function (): void {
    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-bonus.centers.create'))
        ->assertOk()
        ->assertSee('— Select state —', false)
        ->assertSee('Telangana')
        ->assertSee('Ladakh')
        ->assertSee('Dadra and Nagar Haveli and Daman and Diu');
});

it('lists the structured address on the centres screen', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');
    AreteCenter::create([
        'name' => 'Medak Center',
        'location' => null,
        'pincode' => '502001',
        'district' => null,
        'state' => 'Telangana',
        'assigned_distributor_id' => $assignee->id,
        'status' => AreteCenter::STATUS_ACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);

    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-bonus.centers.index'))
        ->assertOk()
        ->assertSee('Pincode / District / State')
        ->assertSee('502001 · — · Telangana', false);
});

it('rejects an unknown status filter', function (): void {
    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-calculation.index', ['status' => 'nonsense']))
        ->assertSessionHasErrors('status');
});

it('hides the ADC calculation report while the feature is off', function (): void {
    Feature::for(null)->deactivate(AreteDevelopmentCenterBonusFeature::class);

    $admin = adcReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.adc-calculation.index'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.compensation.adc-calculation.export'))
        ->assertNotFound();
});

it('edits a centre and audit-logs the before and after', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');
    $newAssignee = adcReportDistributor('ADCNEW', 'Nandini');

    $center = AreteCenter::create([
        'name' => 'Medak Center',
        'location' => 'Main Road',
        'pincode' => '502001',
        'district' => 'Medak',
        'state' => 'Telangana',
        'assigned_distributor_id' => $assignee->id,
        'status' => AreteCenter::STATUS_INACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);

    $this->actingAs(adcReportAdmin())
        ->put(route('admin.compensation.adc-bonus.centers.update', $center), [
            'name' => 'Sangareddy Center',
            'location' => 'Ring Road',
            'pincode' => '502285',
            'district' => 'Sangareddy',
            'state' => 'Telangana',
            'assigned_adn' => $newAssignee->adn,
        ])
        ->assertRedirect(route('admin.compensation.adc-bonus.centers.index'));

    $center->refresh();
    expect($center->name)->toBe('Sangareddy Center')
        ->and($center->pincode)->toBe('502285')
        ->and($center->district)->toBe('Sangareddy')
        ->and($center->assigned_distributor_id)->toBe($newAssignee->id)
        // Status is not on the form, so an edit must leave it alone.
        ->and($center->status)->toBe(AreteCenter::STATUS_INACTIVE);

    $audit = AuditLog::where('action', 'adc.center.updated')
        ->where('subject_id', $center->id)
        ->firstOrFail();
    expect($audit->details['before']['name'])->toBe('Medak Center')
        ->and($audit->details['before']['assigned_distributor_id'])->toBe($assignee->id)
        ->and($audit->details['after']['name'])->toBe('Sangareddy Center')
        ->and($audit->details['after']['assigned_distributor_id'])->toBe($newAssignee->id);
});

it('audit-logs a centre creation', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');

    $this->actingAs(adcReportAdmin())
        ->post(route('admin.compensation.adc-bonus.centers.store'), [
            'name' => 'Medak Center',
            'pincode' => '502001',
            'assigned_adn' => $assignee->adn,
        ])
        ->assertRedirect(route('admin.compensation.adc-bonus.centers.index'));

    $center = AreteCenter::where('name', 'Medak Center')->firstOrFail();
    $audit = AuditLog::where('action', 'adc.center.created')
        ->where('subject_id', $center->id)
        ->firstOrFail();

    expect($audit->details['before'])->toBeNull()
        ->and($audit->details['after']['name'])->toBe('Medak Center')
        ->and($audit->details['after']['pincode'])->toBe('502001')
        ->and($audit->details['after']['status'])->toBe(AreteCenter::STATUS_ACTIVE);
});

it('prefills the edit form with the stored centre and preselects its state', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');

    $center = AreteCenter::create([
        'name' => 'Medak Center',
        'location' => 'Main Road',
        'pincode' => '502001',
        'district' => 'Medak',
        'state' => 'Telangana',
        'assigned_distributor_id' => $assignee->id,
        'status' => AreteCenter::STATUS_ACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);

    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-bonus.centers.edit', $center))
        ->assertOk()
        ->assertSee('Edit Center')
        ->assertSee('value="Medak Center"', false)
        ->assertSee('value="502001"', false)
        ->assertSee('value="'.$assignee->adn.'"', false)
        ->assertSee('<option value="Telangana" selected>Telangana</option>', false);
});

it('links each centre row to its edit form', function (): void {
    $assignee = adcReportDistributor('ADCOWN', 'Owner');

    $center = AreteCenter::create([
        'name' => 'Medak Center',
        'location' => null,
        'pincode' => '502001',
        'district' => null,
        'state' => 'Telangana',
        'assigned_distributor_id' => $assignee->id,
        'status' => AreteCenter::STATUS_ACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);

    $this->actingAs(adcReportAdmin())
        ->get(route('admin.compensation.adc-bonus.centers.index'))
        ->assertOk()
        ->assertSee(route('admin.compensation.adc-bonus.centers.edit', $center), false);
});
