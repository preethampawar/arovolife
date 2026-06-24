<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

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
}
