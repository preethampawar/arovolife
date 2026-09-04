<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payout_batch_id
 * @property int $distributor_id
 * @property int $gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $wallet_balance_paise
 * @property int $repurchase_deduction_paise
 * @property int $net_transferred_paise
 * @property string|null $bank_account_last4
 * @property string|null $utr_number
 * @property string $status
 * @property string|null $failure_reason
 * @property string|null $razorpay_payout_id
 * @property string|null $razorpay_contact_id
 * @property string|null $razorpay_fund_account_id
 * @property string|null $transfer_mode
 * @property int $retry_count
 * @property Carbon|null $last_retried_at
 * @property Carbon|null $dispatched_at
 */
final class PayoutLineItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BELOW_MINIMUM = 'below_minimum';

    /** Personal BV < 3,000 BV (Retailer): balance held in wallet, NEFT blocked. */
    public const STATUS_WEB_ONLY = 'web_only';

    /** Income gates passed but no bank account on file — held in wallet, not debited or swept. */
    public const STATUS_NO_BANK_ACCOUNT = 'no_bank_account';

    /** KYC not yet verified (user.status !== 'active') — balance held in wallet, NEFT blocked until KYC approval. */
    public const STATUS_KYC_PENDING = 'kyc_pending';

    /** Bank account on file but the ciphertext failed to decrypt (LOG-2) — held in wallet, never swept, NEFT blocked until bank details are re-captured. */
    public const STATUS_BANK_DECRYPT_FAILED = 'bank_decrypt_failed';

    protected $table = 'payout_line_items';

    protected $fillable = [
        'payout_batch_id', 'distributor_id',
        'gross_paise', 'admin_charge_paise', 'tds_paise',
        'wallet_balance_paise', 'repurchase_deduction_paise', 'net_transferred_paise',
        'bank_account_last4', 'utr_number', 'status', 'failure_reason',
        'razorpay_payout_id', 'razorpay_contact_id', 'razorpay_fund_account_id',
        'transfer_mode', 'retry_count', 'last_retried_at', 'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_paise' => 'integer',
            'admin_charge_paise' => 'integer',
            'tds_paise' => 'integer',
            'wallet_balance_paise' => 'integer',
            'repurchase_deduction_paise' => 'integer',
            'net_transferred_paise' => 'integer',
            'retry_count' => 'integer',
            'last_retried_at' => 'datetime',
            'dispatched_at' => 'datetime',
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
