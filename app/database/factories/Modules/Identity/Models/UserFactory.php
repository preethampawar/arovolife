<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('password'),
            'status' => 'active',
        ];
    }
}
