<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One distributor's repurchase obligation for a single monthly cycle.
 *
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $cycle_start_date
 * @property Carbon $due_date
 * @property Carbon $grace_end_date
 * @property int $required_bv_paise
 * @property int $completed_bv_paise
 * @property string $status
 * @property Carbon|null $completed_at
 */
final class RepurchaseCycle extends Model
{
    /** Within the cycle window, obligation not yet met but not yet due. */
    public const STATUS_ACTIVE = 'active';

    /** Past due, inside the grace window — income is calculated but held. */
    public const STATUS_GRACE = 'grace';

    /** Grace lapsed without meeting the obligation — GSB/Fortune/GBB suspended. */
    public const STATUS_SUSPENDED = 'suspended';

    /** Obligation met for the cycle — fully income-eligible. */
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'distributor_id',
        'cycle_start_date',
        'due_date',
        'grace_end_date',
        'required_bv_paise',
        'completed_bv_paise',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start_date' => 'date',
            'due_date' => 'date',
            'grace_end_date' => 'date',
            'required_bv_paise' => 'integer',
            'completed_bv_paise' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
