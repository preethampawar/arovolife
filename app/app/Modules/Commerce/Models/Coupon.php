<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Coupon extends Model
{
    protected $table = 'coupons';

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const SCOPE_ALL = 'all';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_PRODUCT = 'product';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'code', 'description', 'type', 'value', 'max_discount_paise',
        'min_purchase_paise', 'scope', 'scope_id', 'starts_at', 'ends_at',
        'usage_limit', 'per_customer_limit', 'used_count', 'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'int',
            'max_discount_paise' => 'int',
            'min_purchase_paise' => 'int',
            'scope_id' => 'int',
            'usage_limit' => 'int',
            'per_customer_limit' => 'int',
            'used_count' => 'int',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * True when "now" falls within the optional starts_at / ends_at window.
     */
    public function isWithinWindow(): bool
    {
        $now = now();
        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function hasUsesLeft(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    /**
     * Human label for the discount, e.g. "10% off" or "₹100 off".
     */
    public function displayValue(): string
    {
        return $this->type === self::TYPE_PERCENT
            ? $this->value.'% off'
            : '₹'.number_format($this->value / 100, 2).' off';
    }
}
