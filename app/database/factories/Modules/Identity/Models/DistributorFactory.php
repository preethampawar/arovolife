<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Distributor>
 */
class DistributorFactory extends Factory
{
    protected $model = Distributor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => hash('sha256', fake()->numerify('#####################')),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now(),
            'cooling_off_end_at' => now()->addDays(30),
            'state' => 'active',
        ];
    }
}
