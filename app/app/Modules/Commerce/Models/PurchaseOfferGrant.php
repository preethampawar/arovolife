<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Commerce\Enums\PurchaseOfferType;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One offer earned by one distributor in one month.
 *
 * `qualifying_bv_paise` and `streak_months` record what was done to earn it.
 * They are stored rather than recomputed because hard rule 2 requires every
 * grant to trace to product sales, and that trace has to survive later changes
 * to thresholds, ranks and ledger data.
 *
 * @property int $id
 * @property int $distributor_id
 * @property PurchaseOfferType $offer_type
 * @property \Illuminate\Support\Carbon $month_start
 * @property int $qualifying_bv_paise
 * @property int $streak_months
 * @property int|null $product_variant_id
 * @property int $points_awarded
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $expires_on
 * @property int|null $consumed_order_id
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property-read Distributor $distributor
 * @property-read ProductVariant|null $variant
 */
final class PurchaseOfferGrant extends Model
{
    protected $table = 'purchase_offer_grants';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'distributor_id', 'offer_type', 'month_start',
        'qualifying_bv_paise', 'streak_months',
        'product_variant_id', 'points_awarded',
        'status', 'expires_on', 'consumed_order_id', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'offer_type' => PurchaseOfferType::class,
            'month_start' => 'date',
            'expires_on' => 'date',
            'consumed_at' => 'datetime',
            'qualifying_bv_paise' => 'integer',
            'streak_months' => 'integer',
            'points_awarded' => 'integer',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Grants that can still be used.
     *
     * @param  Builder<PurchaseOfferGrant>  $query
     * @return Builder<PurchaseOfferGrant>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_GRANTED)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString());
            });
    }

    public function hasLapsed(): bool
    {
        return $this->status === self::STATUS_GRANTED
            && $this->expires_on !== null
            && $this->expires_on->isPast();
    }
}
