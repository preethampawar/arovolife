<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payout_batch_id
 * @property int $distributor_id
 * @property int $wallet_balance_paise
 * @property int $repurchase_deduction_paise
 * @property int $net_transferred_paise
 * @property string|null $bank_account_last4
 * @property string|null $utr_number
 * @property string $status
 * @property string|null $failure_reason
 */
final class PayoutLineItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BELOW_MINIMUM = 'below_minimum';

    /** Personal BV < 3,000 BV (Retailer): balance held in wallet, NEFT blocked. */
    public const STATUS_WEB_ONLY = 'web_only';

    protected $table = 'payout_line_items';

    protected $fillable = [
        'payout_batch_id', 'distributor_id',
        'wallet_balance_paise', 'repurchase_deduction_paise', 'net_transferred_paise',
        'bank_account_last4', 'utr_number', 'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'wallet_balance_paise' => 'integer',
            'repurchase_deduction_paise' => 'integer',
            'net_transferred_paise' => 'integer',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    public function payoutBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }
}
