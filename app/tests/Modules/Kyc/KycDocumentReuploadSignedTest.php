<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * SEC-B4 — the single-document re-upload link is a 14-day signed URL. The GET
 * was signed; the POST was not, so any authenticated distributor could submit
 * to it directly, forever. Both verbs are now behind the `signed` middleware
 * and the form posts back to the signed URL the visitor arrived on.
 */
/**
 * @return array{user: User, document: KycDocument}
 */
function kdrSeedFlaggedDocument(): array
{
    $user = User::create([
        'email' => 'kdr-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Reupload Subject',
        'status' => 'pending',
        'activated_at' => null,
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_ref' => 'STUB_KDR_'.uniqid(),
            'aadhaar_last4' => '0000',
            'bank_account_enc' => null,
            'bank_ifsc' => null,
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
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
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    $document = KycDocument::create([
        'distributor_id' => $id,
        'type' => 'pan',
        'object_storage_key' => "distributor_{$id}/pan_seed.png",
        'checksum_sha256' => random_bytes(32),
        'verified_at' => null,
        'verifier_id' => null,
        'flagged_reason' => 'The PAN number is out of frame.',
        'flagged_at' => now(),
        'flagged_by' => $user->id,
    ]);

    return ['user' => $user, 'document' => $document];
}

it('KDR-01: an unsigned POST to the re-upload route is refused', function () {
    Storage::fake('kyc');
    ['user' => $user, 'document' => $document] = kdrSeedFlaggedDocument();

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('kyc.reupload.store', $document->id), [
            'document' => UploadedFile::fake()->image('pan.jpg', 600, 400),
        ])
        ->assertForbidden();

    // Nothing was written.
    expect($document->fresh()->flagged_at)->not->toBeNull();
});

it('KDR-02: a POST carrying the GET link signature succeeds', function () {
    Storage::fake('kyc');
    ['user' => $user, 'document' => $document] = kdrSeedFlaggedDocument();

    // The signature covers the path + query only, so the URL minted for the
    // GET link validates the POST the form makes back to it.
    $signed = URL::temporarySignedRoute(
        'kyc.reupload.show',
        now()->addDays(14),
        ['document' => $document->id],
    );

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post($signed, ['document' => UploadedFile::fake()->image('pan.jpg', 600, 400)])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    expect($document->fresh()->flagged_at)->toBeNull();
});
