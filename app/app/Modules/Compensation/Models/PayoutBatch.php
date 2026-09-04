<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $batch_type
 * @property Carbon $batch_date
 * @property string $status
 * @property int $total_gross_paise
 * @property int $total_deductions_paise
 * @property int $total_net_paise
 * @property int $distributor_count
 * @property Carbon|null $processed_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 */
final class PayoutBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * At least one distributor threw during batch processing; every other
     * distributor was still processed and their line items stand. Re-running
     * the same batch date retries only the failed distributors (the
     * per-distributor line-item guard skips the rest) and flips the batch
     * back to pending once all succeed.
     */
    public const STATUS_PARTIALLY_FAILED = 'partially_failed';

    /**
     * Finance signed the batch off; no money has moved yet.
     *
     * In manual-NEFT mode this is where a batch waits while the CSV is
     * downloaded, uploaded to the bank, and the bank's response file imported
     * back — which is what promotes it to completed / partially_failed.
     */
    public const STATUS_APPROVED = 'approved';

    /**
     * Razorpay mode: every line item has been handed to the gateway and the
     * batch is waiting for the payout webhooks that finalise each transfer.
     */
    public const STATUS_DISPATCHED = 'dispatched';

    public const TYPE_GSB_WEEKLY = 'gsb_weekly';

    public const TYPE_MANUAL = 'manual';

    /** Per-stream weekly batch: GSB + Mentorship Bonus (Wed→Tue, paid next Tuesday). */
    public const TYPE_WEEKLY = 'weekly';

    /** Per-stream monthly batch: GBB + Rank + Fortune + Awards + ADC (paid on 8th). */
    public const TYPE_MONTHLY = 'monthly';

    protected $table = 'payout_batches';

    protected $fillable = [
        'batch_type', 'batch_date', 'status',
        'total_gross_paise', 'total_deductions_paise', 'total_net_paise',
        'distributor_count', 'processed_at', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
            'total_gross_paise' => 'integer',
            'total_deductions_paise' => 'integer',
            'total_net_paise' => 'integer',
            'distributor_count' => 'integer',
        ];
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(PayoutLineItem::class, 'payout_batch_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
