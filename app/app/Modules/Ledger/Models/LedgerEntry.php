<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ledger_tx_id
 * @property int $account_id
 * @property string $side
 * @property int $amount_paise
 * @property string $currency
 * @property-read LedgerAccount $account
 */
final class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';

    public $timestamps = false;

    protected $fillable = ['ledger_tx_id', 'account_id', 'side', 'amount_paise', 'currency'];

    /** @return BelongsTo<LedgerTx, $this> */
    public function tx(): BelongsTo
    {
        return $this->belongsTo(LedgerTx::class, 'ledger_tx_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
