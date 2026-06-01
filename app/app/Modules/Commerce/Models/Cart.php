<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Cart extends Model
{
    protected $table = 'carts';

    protected $fillable = [
        'customer_id', 'anonymous_key', 'ref_adn_snapshot', 'coupon_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function subtotalPaise(): int
    {
        return (int) $this->items->sum(fn (CartItem $i) => $i->qty * $i->unit_price_paise);
    }

    public function gstPaise(): int
    {
        return (int) $this->items->sum(function (CartItem $i) {
            $line = $i->qty * $i->unit_price_paise;

            return (int) round($line * $i->gst_rate_bp / (10000 + $i->gst_rate_bp));
        });
    }

    public function totalPaise(): int
    {
        return $this->subtotalPaise(); // prices include GST
    }
}
