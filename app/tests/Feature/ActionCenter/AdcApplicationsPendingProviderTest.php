<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\People\AdcApplicationsPendingProvider;
use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(AdcApplicationsPendingProvider::class);
});

function areteCenterApplication(string $status): AreteCenterApplication
{
    return AreteCenterApplication::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'status' => $status,
        'centre_name' => 'Test Centre',
        'address_line_1' => '1 Main Rd',
        'landmark' => 'Near market',
        'pincode' => '500001',
        'city' => 'Hyderabad',
        'state' => 'Telangana',
        'property_type' => 'owned',
        'premises_sqft' => 500,
        'distance_to_nearest_adc_km' => '5',
        'opening_time' => '09:00',
        'closing_time' => '18:00',
        'weekly_off' => 'Sunday',
        'submitted_at' => now(),
    ]);
}

it('is hidden when AreteCenterApplicationsFeature is off', function (): void {
    Feature::for(null)->deactivate(AreteCenterApplicationsFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts an open application once the feature is on', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    $application = areteCenterApplication(AreteCenterApplication::STATUS_SUBMITTED);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($application->id);
});

it('ignores an approved or rejected application', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    areteCenterApplication(AreteCenterApplication::STATUS_APPROVED);
    areteCenterApplication(AreteCenterApplication::STATUS_REJECTED);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed application', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    $application = areteCenterApplication(AreteCenterApplication::STATUS_SUBMITTED);

    ActionCenterSnooze::create([
        'action_key' => 'adc.applications_pending',
        'subject_type' => 'arete_center_application',
        'subject_id' => $application->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Awaiting a site visit.',
    ]);

    expect($this->provider->count())->toBe(0);
});
