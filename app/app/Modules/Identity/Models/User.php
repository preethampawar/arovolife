<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $email
 * @property string $phone_e164
 * @property string $password_hash
 * @property string|null $mfa_secret_enc
 * @property Carbon|null $mfa_enabled_at
 * @property string|null $full_name
 * @property string|null $date_of_birth
 * @property string $status
 * @property Carbon|null $last_login_at
 * @property string|null $remember_token
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $password_set_at
 * @property-read Distributor|null $distributor
 */
final class User extends Authenticatable
{
    use HasRoles, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'full_name',
        'email',
        'phone_e164',
        'password_hash',
        'password_set_at',
        'mfa_secret_enc',
        'mfa_enabled_at',
        'date_of_birth',
        'status',
        'last_login_at',
        'remember_token',
        'email_verified_at',
    ];

    protected $hidden = [
        'password_hash',
        'mfa_secret_enc',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'mfa_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function distributor(): HasOne
    {
        return $this->hasOne(Distributor::class);
    }
}
