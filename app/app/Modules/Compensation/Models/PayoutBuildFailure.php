<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A distributor the payout sweep skipped because their line threw while being
 * built. Their credits stay unswept in the wallet; nothing was debited.
 *
 * @property int $id
 * @property int $payout_batch_id
 * @property int $distributor_id
 * @property string $status
 * @property string|null $resolution
 * @property int|null $resolved_line_item_id
 * @property int|null $resolved_by_batch_id
 * @property int $attempts
 * @property string $exception_class
 * @property string $message
 * @property string $error_detail
 * @property Carbon $first_failed_at
 * @property Carbon $last_failed_at
 * @property Carbon|null $resolved_at
 * @property int|null $retry_requested_by
 * @property Carbon|null $retry_requested_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PayoutBatch|null $payoutBatch
 * @property-read PayoutBatch|null $resolvedByBatch
 * @property-read Distributor|null $distributor
 */
final class PayoutBuildFailure extends Model
{
    public const STATUS_OPEN = 'open';

    /** A retry job is queued or running for this row. */
    public const STATUS_RETRYING = 'retrying';

    public const STATUS_RESOLVED = 'resolved';

    /** @var list<string> */
    public const UNRESOLVED_STATUSES = [self::STATUS_OPEN, self::STATUS_RETRYING];

    /** A retry built the distributor's line into the same batch. */
    public const RESOLUTION_BUILT = 'built';

    /** The batch was approved without them; a later batch swept their credits. */
    public const RESOLUTION_PAID_BY_LATER_BATCH = 'paid_by_later_batch';

    protected $table = 'payout_build_failures';

    protected $fillable = [
        'payout_batch_id', 'distributor_id', 'status', 'resolution', 'resolved_line_item_id',
        'resolved_by_batch_id', 'attempts', 'exception_class', 'message', 'error_detail',
        'first_failed_at', 'last_failed_at', 'resolved_at', 'retry_requested_by', 'retry_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'payout_batch_id' => 'integer',
            'distributor_id' => 'integer',
            'resolved_line_item_id' => 'integer',
            'resolved_by_batch_id' => 'integer',
            'attempts' => 'integer',
            'retry_requested_by' => 'integer',
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'retry_requested_at' => 'datetime',
        ];
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /** @return BelongsTo<PayoutBatch, $this> */
    public function payoutBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /** @return BelongsTo<PayoutBatch, $this> */
    public function resolvedByBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'resolved_by_batch_id');
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }
}
