<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerTx extends Model
{
    protected $table = 'ledger_tx';

    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'source_module',
        'source_type',
        'source_id',
        'idempotency_key',
        'memo',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_tx_id');
    }
}
