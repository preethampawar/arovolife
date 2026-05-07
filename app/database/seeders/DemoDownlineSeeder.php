<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Builds a small wide demo tree under a synthetic L0 root, exercising the
 * single-level placement engine introduced in ADR-0003. Resulting shape:
 *
 *                 L0  (root, 111222333)
 *               /    \
 *             J1      J2
 *           /   \    /
 *         J3    J4  J5
 *       (couple on J1)
 *
 * Run with:  php artisan db:seed --class=DemoDownlineSeeder
 */
final class DemoDownlineSeeder extends Seeder
{
    private const PASSWORD = 'demo123456';

    public function run(): void
    {
        if (User::query()->where('email', 'demo-l0@arovolife.test')->exists()) {
            $this->command->warn('Demo downline already present — skipping. Delete demo-* users to rebuild.');

            return;
        }

        $passwordHash = Hash::make(self::PASSWORD);

        // ── L0 root: self-referencing distributor (no real sponsor exists
        //    yet, so we bootstrap with raw inserts before the engine takes over).
        $rootUser = User::create([
            'full_name' => 'Demo L0 Root',
            'email' => 'demo-l0@arovolife.test',
            'phone_e164' => '+919800000000',
            'password_hash' => $passwordHash,
            'password_set_at' => now(),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $now = now()->format('Y-m-d H:i:s.v');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $rootId = DB::table('distributors')->insertGetId([
                'user_id' => $rootUser->id,
                'adn' => '111222333',
                'pan_hash' => random_bytes(32),
                'pan_last4' => '0000',
                'aadhaar_ref' => 'STUB_DEMO_L0',
                'aadhaar_last4' => '0000',
                'bank_account_enc' => Crypt::encryptString('000000000000'),
                'bank_ifsc' => 'HDFC0000000',
                'sponsor_id' => 1,           // placeholder — rewritten below
                'placement_parent_id' => 1,
                'placement_side' => null,
                'side_chosen_by' => 'referral_default',
                'depth' => 0,
                'effective_date' => $now,
                'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
                'state' => 'TG',
                'is_primary_couple' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('distributors')->where('id', $rootId)->update([
                'sponsor_id' => $rootId,
                'placement_parent_id' => $rootId,
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $rootId,
            'descendant_id' => $rootId,
            'depth' => 0,
        ]);

        $created = [['L0', $rootUser->email, '111222333', $rootId, 'root']];

        // ── J1..J5 placed via the engine. We vary placement_id so the tree
        // grows wide rather than chaining (single-level placement means each
        // node holds at most 2 directs).
        $engine = app(PlacementEngine::class);
        $idx = 1;

        $placeJoiner = function (string $label, int $sponsorId, int $placementId, ?string $side, int &$idx) use ($engine, $passwordHash, &$created): int {
            $email = "demo-{$label}@arovolife.test";
            $user = User::create([
                'full_name' => "Demo {$label} Distributor",
                'email' => $email,
                'phone_e164' => '+9198100000'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
                'password_hash' => $passwordHash,
                'password_set_at' => now(),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $input = new PlaceDistributorInput(
                userId: $user->id,
                sponsorId: $sponsorId,
                placementId: $placementId,
                panHash: random_bytes(32),
                panLast4: str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
                bankAccountEnc: Crypt::encryptString('1234567890'.$idx),
                bankIfsc: 'HDFC0000001',
                state: 'TG',
                sideOpt: $side,
                aadhaarRef: 'STUB_DEMO_'.strtoupper($label),
                aadhaarLast4: str_pad((string) $idx, 4, '0', STR_PAD_LEFT),
                isPrimaryCouple: false,
            );

            $result = $engine->place($input);
            $adn = (string) DB::table('distributors')->where('id', $result->distributorId)->value('adn');
            $created[] = [strtoupper($label), $email, $adn, $result->distributorId, $result->side];
            $idx++;

            return $result->distributorId;
        };

        // J1: under L0, no side → fills L0.L (referral_default)
        $j1 = $placeJoiner('j1', $rootId, $rootId, null, $idx);

        // J2: under L0, no side → L is taken, fallback to L0.R (referral_fallback_right)
        $j2 = $placeJoiner('j2', $rootId, $rootId, null, $idx);

        // J3: sponsored by L0, placed under J1, no side → J1.L
        $j3 = $placeJoiner('j3', $rootId, $j1, null, $idx);

        // J4: sponsored by L0, placed under J1 → J1.L taken, fallback to J1.R
        $j4 = $placeJoiner('j4', $rootId, $j1, null, $idx);

        // J5: sponsored by L0, placed under J2 → J2.L
        $j5 = $placeJoiner('j5', $rootId, $j2, null, $idx);

        // ── Couple registration on J1 — secondary doesn't take a tree slot
        // (mirrors RegistrationService::createSecondaryDistributor).
        $primary = DB::table('distributors')->where('id', $j1)->first();
        $spouseEmail = 'demo-j1-spouse@arovolife.test';
        $spouseUser = User::create([
            'full_name' => 'Demo J1 Spouse',
            'email' => $spouseEmail,
            'phone_e164' => '+919820000001',
            'password_hash' => $passwordHash,
            'password_set_at' => now(),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $spouseId = DB::table('distributors')->insertGetId([
            'user_id' => $spouseUser->id,
            'adn' => $primary->adn.'-S',
            'pan_hash' => random_bytes(32),
            'pan_last4' => 'SP01',
            'aadhaar_ref' => 'STUB_DEMO_J1_SPOUSE',
            'aadhaar_last4' => '0001',
            'bank_account_enc' => Crypt::encryptString('1234567890'),
            'bank_ifsc' => 'HDFC0000001',
            'sponsor_id' => $primary->sponsor_id,
            'placement_id_at_registration' => null,
            'placement_parent_id' => $primary->id,
            'placement_side' => null,                       // NOT in tree
            'side_chosen_by' => $primary->side_chosen_by,
            'depth' => $primary->depth,
            'effective_date' => $now,
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => $primary->state,
            'spouse_distributor_id' => $primary->id,
            'is_primary_couple' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('distributors')->where('id', $primary->id)->update([
            'spouse_distributor_id' => $spouseId,
            'is_primary_couple' => 1,
        ]);

        DB::table('cooling_off_events')->insert([
            'distributor_id' => $spouseId,
            'opened_at' => $now,
        ]);

        // ── Print credentials
        $this->command->info('────────────────────────────────────────────────────────────');
        $this->command->info('Demo tree created (ADR-0003 single-level placement)');
        $this->command->info('Password for every account: '.self::PASSWORD);
        $this->command->info('────────────────────────────────────────────────────────────');
        $this->command->info('  L0 (root)');
        $this->command->info('    /            \\');
        $this->command->info('   J1            J2');
        $this->command->info('   /  \\          /');
        $this->command->info('  J3   J4       J5');
        $this->command->info(' (J1 has a couple-spouse, sharing one tree slot)');
        $this->command->info('────────────────────────────────────────────────────────────');
        foreach ($created as [$label, $email, $adn, $id, $side]) {
            $this->command->info(sprintf('  %-3s  %-32s  ADN=%-12s  side=%s  id=%d', $label, $email, $adn, $side, $id));
        }
        $this->command->info(sprintf('  SP   %-32s  ADN=%-12s  (linked to %s)', $spouseEmail, $primary->adn.'-S', $primary->adn));
        $this->command->info('────────────────────────────────────────────────────────────');
        $this->command->info('Sign in as L0 to see the full tree at /tree (binary view).');
        $this->command->info('Each distributor sees a "My Referral Link" widget on their dashboard.');
    }
}
