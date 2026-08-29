<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $type
 * @property int $amount_paise
 * @property int|null $reference_id
 * @property string|null $reference_type
 * @property string|null $memo
 * @property int|null $swept_by_payout_batch_id
 * @property int|null $engine_run_id
 * @property Carbon $created_at
 */
final class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'wallet_ledger_entries';

    protected $fillable = [
        'distributor_id', 'type', 'amount_paise',
        'reference_id', 'reference_type', 'memo',
        'swept_by_payout_batch_id', 'engine_run_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'reference_id' => 'integer',
            'swept_by_payout_batch_id' => 'integer',
            'engine_run_id' => 'integer',
        ];
    }

    /**
     * The engine run that wrote this entry, or null when it was written outside
     * one (order-time repurchase entries, manual admin credits).
     *
     * @return BelongsTo<EngineRun, $this>
     */
    public function engineRun(): BelongsTo
    {
        return $this->belongsTo(EngineRun::class, 'engine_run_id');
    }
}
