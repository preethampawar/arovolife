<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property string $invoice_no
 * @property Carbon $issued_at
 * @property string|null $seller_gstin
 * @property string $seller_state
 * @property string|null $buyer_gstin
 * @property string $buyer_state
 * @property string $place_of_supply
 * @property int $subtotal_paise
 * @property int $cgst_paise
 * @property int $sgst_paise
 * @property int $igst_paise
 * @property int $cess_paise
 * @property int $total_paise
 */
final class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'order_id', 'invoice_no', 'irn', 'issued_at',
        'seller_gstin', 'seller_state',
        'buyer_gstin', 'buyer_state', 'place_of_supply',
        'subtotal_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise', 'total_paise',
        'pdf_hash_sha256', 'pdf_storage_key',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'subtotal_paise' => 'int',
            'cgst_paise' => 'int',
            'sgst_paise' => 'int',
            'igst_paise' => 'int',
            'cess_paise' => 'int',
            'total_paise' => 'int',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
