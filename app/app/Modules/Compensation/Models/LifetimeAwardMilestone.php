<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $rank_number
 * @property int $tranche
 * @property int $amount_paise
 * @property Carbon $triggered_month
 * @property int $qualification_count
 * @property string $award_description
 * @property string $status
 * @property Carbon|null $released_rule_changed_at
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 */
final class LifetimeAwardMilestone extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'tranche',
        'amount_paise',
        'triggered_month',
        'qualification_count',
        'award_description',
        'status',
        'released_rule_changed_at',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'tranche' => 'int',
            'amount_paise' => 'int',
            'triggered_month' => 'date',
            'qualification_count' => 'int',
            'delivered_at' => 'datetime',
            'released_rule_changed_at' => 'datetime',
        ];
    }

    /** A tranche is released once the rank has been qualified at least `tranche` times (client 2026-10-09). */
    public function isReleasable(): bool
    {
        return $this->qualification_count >= $this->tranche;
    }

    /** The tranche as the plan names it: 1 → A, 2 → B, 3 → C. */
    public function trancheLetter(): string
    {
        return chr(64 + $this->tranche);
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
