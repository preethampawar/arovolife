<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $type
 * @property int $amount_paise
 * @property int|null $reference_id
 * @property string|null $reference_type
 * @property string|null $memo
 * @property Carbon $created_at
 */
final class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'wallet_ledger_entries';

    protected $fillable = [
        'distributor_id', 'type', 'amount_paise',
        'reference_id', 'reference_type', 'memo',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'reference_id' => 'integer',
        ];
    }
}
