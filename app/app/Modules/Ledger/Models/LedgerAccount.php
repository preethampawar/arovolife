<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

final class LedgerAccount extends Model
{
    protected $table = 'ledger_accounts';

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    protected $fillable = ['code', 'name', 'type', 'parent_id', 'currency'];
}
