<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $distributor_id
 * @property string $display_name
 * @property string|null $email_hash
 * @property string|null $email_enc
 * @property string|null $phone_hash
 * @property string|null $phone_enc
 * @property string|null $phone_last4
 * @property bool $marketing_opt_in
 * @property Carbon|null $claimed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'user_id', 'distributor_id', 'display_name',
        'email_hash', 'email_enc', 'phone_hash', 'phone_enc', 'phone_last4',
        'marketing_opt_in', 'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'marketing_opt_in' => 'bool',
            'claimed_at' => 'datetime',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }
}
