<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The company-announced product carrying the half-price offer in a month.
 *
 * One per month, enforced by a unique index: "the company's monthly-announced
 * product" is singular, and a schema that allowed two would let the announcement
 * and the entitlement disagree.
 *
 * @property int $id
 * @property Carbon $month_start
 * @property int $product_variant_id
 * @property int|null $created_by_user_id
 * @property string|null $notes
 * @property-read ProductVariant $variant
 * @property-read User|null $createdBy
 */
final class MonthlyOfferProduct extends Model
{
    protected $table = 'monthly_offer_products';

    protected $fillable = [
        'month_start', 'product_variant_id', 'created_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['month_start' => 'date'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
