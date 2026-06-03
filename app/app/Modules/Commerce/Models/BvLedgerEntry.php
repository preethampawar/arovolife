<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only entry in the personal-BV ledger (ADR-0006). Never updated or
 * deleted in normal operation — a refund adds a negative `reversal` rather than
 * mutating the original `accrual`.
 *
 * @property int $id
 * @property int $distributor_id
 * @property int $order_id
 * @property int $bv_paise
 * @property string $type
 * @property Carbon $effective_at
 * @property-read Distributor|null $distributor
 * @property-read Order|null $order
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

    /**
     * The single definition of the report's effective-date window — every
     * query that scopes BV entries to a period goes through here (Laravel 13
     * `#[Scope]` attribute scope).
     *
     * @param  Builder<BvLedgerEntry>  $query
     */
    #[Scope]
    protected function dateRange(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        $query->when($from, fn (Builder $q) => $q->where('bv_ledger_entries.effective_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('bv_ledger_entries.effective_at', '<=', $to));
    }

    /**
     * @param  Builder<BvLedgerEntry>  $query
     */
    #[Scope]
    protected function forDistributor(Builder $query, int $distributorId): void
    {
        $query->where('bv_ledger_entries.distributor_id', $distributorId);
    }
}
