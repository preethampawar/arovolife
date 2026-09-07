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

    /** Per-stream weekly batch: GSB + Mentorship Bonus. {@see weeklyEarningWindow()}. */
    public const TYPE_WEEKLY = 'weekly';

    /** Per-stream monthly batch: GBB + Rank + Fortune + Awards + ADC (paid on the 8th). */
    public const TYPE_MONTHLY = 'monthly';

    /**
     * The first weekly batch date the Wednesday→Tuesday earning week governs —
     * the first Tuesday after the client confirmed the rule (2026-09-07).
     *
     * Every batch before it, and every legacy `gsb_weekly` batch whenever it
     * ran, swept whatever the wallet held on the batch date; it paid no
     * bounded earning week at all. Printing a window against those batches
     * would state, as historical fact, a period they never paid for, so the
     * reports show them "—" instead. {@see weeklyEarningThrough()}.
     */
    public const string WEEK_RULE_EFFECTIVE_FROM = '2026-09-08';

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

    /**
     * The Wednesday→Tuesday earning week that the weekly batch dated
     * `$batchDate` pays — the ONE place the week rule lives.
     *
     * The client's rule (2026-09-07): "the daily closing weekly payout cycle
     * starts every Wednesday and closes on Tuesday … eligible earnings accrued
     * between Wednesday, August 5, and Tuesday, August 11" are deposited "on
     * Tuesday, August 18". So a batch dated Tuesday `T` pays the week that
     * closed the PREVIOUS Tuesday: `[T − 13, T − 7]`, both inclusive.
     *
     * Keyed on the day the income was EARNED — the GSB cut-off date, the
     * mentorship cut-off day — never on the wallet credit timestamp: Tuesday's
     * cut-off is credited at 00:10 on Wednesday, so a window read off the write
     * time would push every Tuesday's income a week late for ever.
     *
     * The gap is a processing week, NOT a cooling-off period: cooling-off is the
     * statutory 30-day cancellation window (hard rule 5) and the phrase must
     * never be reused for this in distributor-facing copy.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public static function weeklyEarningWindow(Carbon $batchDate): array
    {
        $end = $batchDate->copy()->startOfDay()->subDays(7);

        return [
            'start' => $end->copy()->subDays(6),
            'end' => $end,
        ];
    }

    /**
     * The last day THIS batch actually paid for, or null when the week rule
     * never governed it — a legacy `gsb_weekly` batch, or any batch dated
     * before {@see WEEK_RULE_EFFECTIVE_FROM}.
     *
     * The only place a report is allowed to decide whether a stored batch has
     * an earning window: calling weeklyEarningWindow() on a batch date alone
     * would happily invent one for a batch that swept the wallet instead.
     */
    public function weeklyEarningThrough(): ?Carbon
    {
        if ($this->batch_type !== self::TYPE_WEEKLY || $this->batch_date === null) {
            return null;
        }

        if ($this->batch_date->lessThan(Carbon::parse(self::WEEK_RULE_EFFECTIVE_FROM))) {
            return null;
        }

        return self::weeklyEarningWindow($this->batch_date)['end'];
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
