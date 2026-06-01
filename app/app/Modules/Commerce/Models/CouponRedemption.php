<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CouponRedemption extends Model
{
    public const UPDATED_AT = null; // created_at only

    protected $table = 'coupon_redemptions';

    protected $fillable = [
        'coupon_id', 'order_id', 'customer_id', 'discount_paise',
    ];

    protected function casts(): array
    {
        return [
            'discount_paise' => 'int',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
