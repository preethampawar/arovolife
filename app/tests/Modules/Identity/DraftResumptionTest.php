<?php

declare(strict_types=1);

/**
 * Draft resumption tests — Cookie-based and signed-link draft restoration.
 *
 * RESUME-001: Draft restoration from av_draft cookie → wizard state restored correctly
 * RESUME-002: Correct step redirection after resumption
 */

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('draft_wizard_state_restored_correctly', function () {
    $user = createUser();
    $root = createRoot();

    $draftService = app(DraftStateService::class);
    $wizardService = app(WizardStateService::class);

    // Create a draft with step 5 (PAN) and 6 (Aadhaar) data
    $draftData = [
        'pan' => [
            'pan_number' => 'ABCDE1234F',
            'spouse_pan_number' => null,
        ],
        'aadhaar' => [
            'last4' => '1234',
            'ref' => 'STUB_REF123',
        ],
    ];

    $draft = $draftService->create(
        userId: $user->id,
        sponsorId: $root->id,
        placementId: $root->id,
        sideOpt: 'L',
        data: $draftData,
        currentStep: 7,  // User completed step 6 (Aadhaar), next step is 7 (Bank)
    );

    // Simulate draft restoration via middleware
    $draftService->restoreToWizard($draft, $wizardService);

    // Verify session was properly restored
    $session = $wizardService->get();
    expect($session['step'])->toBe(7);
    expect($session['user_id'])->toBe($user->id);
    expect($session['sponsor_id'])->toBe($root->id);
    expect($session['data']['pan']['pan_number'])->toBe('ABCDE1234F');
    expect($session['data']['aadhaar']['last4'])->toBe('1234');
});

test('step_route_mapping_correct', function () {
    $routes = [
        3 => 'register.orientation',
        4 => 'register.consent',
        5 => 'register.pan',
        6 => 'register.aadhaar',
        7 => 'register.bank',
        8 => 'register.personal',
        9 => 'register.documents',
        10 => 'register.complete',
    ];

    $wizardService = app(WizardStateService::class);

    foreach ($routes as $step => $expectedRoute) {
        expect(WizardStateService::stepRoute($step))->toBe($expectedRoute);
    }
});

test('get_step_data_retrieves_correct_data', function () {
    $user = createUser();
    $root = createRoot();

    $draftService = app(DraftStateService::class);
    $wizardService = app(WizardStateService::class);

    // Create draft with multiple step data
    $draftData = [
        'pan' => [
            'pan_number' => 'ABCDE1234F',
            'spouse_pan_number' => null,
        ],
        'aadhaar' => [
            'last4' => '5678',
            'ref' => 'STUB_REF456',
        ],
        'bank' => [
            'account_number_last4' => '9999',
            'ifsc_code' => 'SBIN0000001',
        ],
    ];

    $draft = $draftService->create(
        userId: $user->id,
        sponsorId: $root->id,
        placementId: $root->id,
        sideOpt: 'R',
        data: $draftData,
        currentStep: 8,
    );

    $draftService->restoreToWizard($draft, $wizardService);

    // Verify each step's data is accessible
    expect($wizardService->getStepData(5)['pan_number'])->toBe('ABCDE1234F');
    expect($wizardService->getStepData(6)['last4'])->toBe('5678');
    expect($wizardService->getStepData(7)['ifsc_code'])->toBe('SBIN0000001');
});

// Helper functions
function createUser()
{
    $userId = DB::table('users')->insertGetId([
        'email' => 'test'.uniqid().'@test.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return \App\Modules\Identity\Models\User::find($userId);
}

function createRoot(): Distributor
{
    $userId = DB::table('users')->insertGetId([
        'email' => 'root'.uniqid().'@test.com',
        'phone_e164' => '+919'.rand(100000000, 999999999),
        'password_hash' => bcrypt('password'),
        'password_set_at' => now(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $adn = (string) rand(100000000, 999999999);

        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => $adn,
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => random_bytes(32),
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id,
            'placement_parent_id' => $id,
        ]);

        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $id,
            'descendant_id' => $id,
            'depth' => 0,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return Distributor::find($id);
}
