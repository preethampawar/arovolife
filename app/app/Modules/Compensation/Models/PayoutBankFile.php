<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Compensation\Services\PayoutBankFileVault;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One bank file of a payout batch — a NEFT file downloaded for the bank or a
 * response file the bank sent back. The bytes live encrypted on the
 * `payout-bank-files` disk ({@see PayoutBankFileVault});
 * this row and its parsed rows outlive them.
 *
 * @property int $id
 * @property int $payout_batch_id
 * @property string $direction
 * @property string|null $storage_key
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property int $row_count
 * @property string $outcome
 * @property array<string, mixed>|null $summary
 * @property int|null $identical_to_id
 * @property int|null $actor_id
 * @property Carbon|null $purged_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PayoutBatch|null $payoutBatch
 * @property-read User|null $actor
 */
final class PayoutBankFile extends Model
{
    public const DISK = 'payout-bank-files';

    public const DIRECTION_EXPORT = 'export';

    public const DIRECTION_IMPORT = 'import';

    /** The file was read and its rows applied (rows it refused are recorded per row). */
    public const OUTCOME_APPLIED = 'applied';

    /** The file could not be read at all — kept, with the reason, and nothing applied. */
    public const OUTCOME_REFUSED = 'refused';

    protected $table = 'payout_bank_files';

    protected $fillable = [
        'payout_batch_id', 'direction', 'storage_key', 'original_name', 'size_bytes',
        'sha256', 'row_count', 'outcome', 'summary', 'identical_to_id', 'actor_id', 'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'payout_batch_id' => 'integer',
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'summary' => 'array',
            'identical_to_id' => 'integer',
            'actor_id' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PayoutBatch, $this> */
    public function payoutBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return HasMany<PayoutBankFileRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(PayoutBankFileRow::class, 'payout_bank_file_id');
    }

    public function isImport(): bool
    {
        return $this->direction === self::DIRECTION_IMPORT;
    }

    public function isPurged(): bool
    {
        return $this->storage_key === null;
    }
}
