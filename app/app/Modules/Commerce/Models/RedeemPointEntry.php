<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in a distributor's redeem-point balance.
 *
 * Append-only, and deliberately not part of the wallet. Redeem points are a
 * discount entitlement earned from a distributor's own purchases — they are
 * not money the company owes, they attract no TDS and no admin charge, and
 * they are never paid out. Mixing them into the wallet ledger would make the
 * wallet's balance mean two different things at once.
 *
 * @property int $id
 * @property int $distributor_id
 * @property int $points  signed: positive accrual, negative redemption or expiry
 * @property string $type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $memo
 * @property int|null $actor_user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Distributor $distributor
 * @property-read User|null $actor
 */
final class RedeemPointEntry extends Model
{
    protected $table = 'redeem_point_entries';

    public $timestamps = false;

    public const TYPE_ACCRUAL = 'accrual';

    public const TYPE_REDEMPTION = 'redemption';

    public const TYPE_EXPIRY = 'expiry';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'distributor_id', 'points', 'type',
        'reference_type', 'reference_id', 'memo', 'actor_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
