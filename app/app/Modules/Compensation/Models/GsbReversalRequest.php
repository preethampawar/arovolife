<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One admin's request to reverse a GSB credit, awaiting a second admin's
 * decision (R-92).
 *
 * @property int $id
 * @property int|null $gsb_cutoff_result_id
 * @property int $distributor_id
 * @property Carbon $cutoff_date
 * @property int $net_gsb_paise
 * @property int $repurchase_deduction_paise
 * @property string $status
 * @property string $reason
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 */
final class GsbReversalRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'gsb_cutoff_result_id', 'distributor_id', 'cutoff_date',
        'net_gsb_paise', 'repurchase_deduction_paise',
        'status', 'reason', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'net_gsb_paise' => 'integer',
            'repurchase_deduction_paise' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GsbCutoffResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(GsbCutoffResult::class, 'gsb_cutoff_result_id');
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
