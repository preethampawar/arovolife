<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerAddress extends Model
{
    protected $table = 'customer_addresses';

    protected $fillable = [
        'customer_id', 'kind', 'name', 'phone_e164',
        'line1', 'line2', 'city', 'state', 'pincode', 'country', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'bool'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
