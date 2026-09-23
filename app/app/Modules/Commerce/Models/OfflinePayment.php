<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The payment behind an offline order: money taken outside the gateway, as
 * staff recorded it when they created the order and as finance then confirmed
 * or rejected it. Nothing here is on the books until confirmation posts the
 * prepayment (PaymentConfirmationService::confirmOffline()).
 *
 * @property int $id
 * @property int $order_id
 * @property int $distributor_id
 * @property string $channel
 * @property string|null $channel_other
 * @property int $amount_paise
 * @property Carbon $received_on
 * @property string|null $reference_no
 * @property string|null $payer_name
 * @property string|null $notes
 * @property string|null $proof_storage_key
 * @property string|null $proof_original_name
 * @property string|null $proof_mime
 * @property int|null $proof_size_bytes
 * @property string|null $proof_sha256
 * @property Carbon|null $proof_purged_at
 * @property string $status
 * @property Carbon $terms_acknowledged_at
 * @property int $recorded_by_user_id
 * @property int|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmation_note
 * @property int|null $rejected_by_user_id
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property string $idempotency_key
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read User|null $recordedBy
 * @property-read User|null $confirmedBy
 * @property-read User|null $rejectedBy
 */
final class OfflinePayment extends Model
{
    protected $table = 'offline_payments';

    public const CHANNEL_CASH = 'cash';

    public const CHANNEL_BANK_DEPOSIT = 'bank_deposit';

    public const CHANNEL_UPI = 'upi';

    public const CHANNEL_BANK_TRANSFER = 'bank_transfer';

    public const CHANNEL_CHEQUE = 'cheque';

    public const CHANNEL_OTHER = 'other';

    /** @var array<string, string> channel => label */
    public const CHANNELS = [
        self::CHANNEL_CASH => 'Cash (at reception)',
        self::CHANNEL_BANK_DEPOSIT => 'Bank deposit',
        self::CHANNEL_UPI => 'UPI',
        self::CHANNEL_BANK_TRANSFER => 'NEFT / IMPS / RTGS',
        self::CHANNEL_CHEQUE => 'Cheque',
        self::CHANNEL_OTHER => 'Other',
    ];

    /** Private disk holding the proof files (ciphertext, see OfflinePaymentProofVault). */
    public const DISK = 'offline-payments';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Income Tax Act s.269ST: nobody may receive ₹2 lakh or more in cash from
     * one person in a day. The penalty (s.271DA) is the whole amount, so the
     * ceiling is a hard refusal, not a warning.
     */
    public const CASH_DAILY_LIMIT_PAISE = 20_000_000;

    protected $fillable = [
        'order_id', 'distributor_id', 'channel', 'channel_other', 'amount_paise', 'received_on',
        'reference_no', 'payer_name', 'notes',
        'proof_storage_key', 'proof_original_name', 'proof_mime', 'proof_size_bytes', 'proof_sha256', 'proof_purged_at',
        'status', 'terms_acknowledged_at', 'recorded_by_user_id',
        'confirmed_by_user_id', 'confirmed_at', 'confirmation_note',
        'rejected_by_user_id', 'rejected_at', 'rejection_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'int',
            'distributor_id' => 'int',
            'amount_paise' => 'int',
            'received_on' => 'date',
            'proof_size_bytes' => 'int',
            'proof_purged_at' => 'datetime',
            'terms_acknowledged_at' => 'datetime',
            'recorded_by_user_id' => 'int',
            'confirmed_by_user_id' => 'int',
            'confirmed_at' => 'datetime',
            'rejected_by_user_id' => 'int',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    /** Where the money sits once confirmed: the office cash drawer, or the bank. */
    public function ledgerAccount(): string
    {
        return $this->channel === self::CHANNEL_CASH ? 'asset.cash.office' : 'asset.cash.bank.settlement';
    }

    public function channelLabel(): string
    {
        if ($this->channel === self::CHANNEL_OTHER && $this->channel_other !== null && $this->channel_other !== '') {
            return 'Other — '.$this->channel_other;
        }

        return self::CHANNELS[$this->channel] ?? ucfirst($this->channel);
    }

    public function hasProof(): bool
    {
        return $this->proof_storage_key !== null;
    }

    /**
     * pending | confirmed | rejected | cancelled. `cancelled` is derived: a
     * payment still pending on an order that was cancelled some other way.
     */
    public function displayState(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->order->status === Order::STATUS_CANCELLED) {
            return 'cancelled';
        }

        return $this->status;
    }

    /** Trim, drop inner spaces and upper-case, so "utr 123" and "UTR123" are one reference. */
    public static function normaliseReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/\s+/', '', $reference));

        return $clean === '' ? null : $clean;
    }
}
