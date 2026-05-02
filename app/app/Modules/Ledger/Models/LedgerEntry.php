<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';

    public $timestamps = false;

    protected $fillable = ['ledger_tx_id', 'account_id', 'side', 'amount_paise', 'currency'];

    public function tx(): BelongsTo
    {
        return $this->belongsTo(LedgerTx::class, 'ledger_tx_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
