<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in the personal-BV ledger (ADR-0006). Never updated or
 * deleted in normal operation — a refund adds a negative `reversal` rather than
 * mutating the original `accrual`.
 */
final class BvLedgerEntry extends Model
{
    public const TYPE_ACCRUAL = 'accrual';

    public const TYPE_REVERSAL = 'reversal';

    protected $table = 'bv_ledger_entries';

    protected $fillable = [
        'distributor_id', 'order_id', 'bv_paise', 'type', 'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'bv_paise' => 'int',
            'effective_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
