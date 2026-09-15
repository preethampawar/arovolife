<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a variant's landing price. Append-only: there is no
 * `updated_at` because a row here is a fact about a moment, not a record that
 * can be revised.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $old_paise
 * @property int $new_paise
 * @property string $source
 * @property int|null $purchase_invoice_id
 * @property int|null $changed_by_user_id
 * @property string|null $note
 * @property CarbonInterface $created_at
 */
final class LandingPriceHistory extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_GRN = 'grn';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BACKFILL = 'backfill';

    protected $table = 'landing_price_history';

    protected $fillable = [
        'product_variant_id', 'old_paise', 'new_paise', 'source',
        'purchase_invoice_id', 'changed_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'int',
            'old_paise' => 'int',
            'new_paise' => 'int',
            'purchase_invoice_id' => 'int',
            'changed_by_user_id' => 'int',
            'created_at' => 'datetime',
        ];
    }

    public function deltaPaise(): int
    {
        return $this->new_paise - $this->old_paise;
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
