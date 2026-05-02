<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceLine extends Model
{
    protected $table = 'invoice_lines';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id', 'order_item_id', 'hsn_code', 'qty',
        'taxable_value_paise', 'gst_rate_bp',
        'cgst_paise', 'sgst_paise', 'igst_paise',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
