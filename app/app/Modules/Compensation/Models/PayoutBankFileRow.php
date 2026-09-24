<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a bank file: what the file said about an ADN and what the
 * platform did with it. Holds no account number, IFSC or name — the import
 * comparisons read these rows, never the encrypted file.
 *
 * @property int $id
 * @property int $payout_bank_file_id
 * @property int $row_no
 * @property string $adn
 * @property int|null $payout_line_item_id
 * @property int|null $attempt
 * @property string|null $bank_status
 * @property string|null $verdict
 * @property string|null $utr
 * @property int|null $amount_paise
 * @property string|null $reason
 * @property string $result
 * @property-read PayoutBankFile|null $file
 */
final class PayoutBankFileRow extends Model
{
    /** Export: the line was in the file handed to the bank. */
    public const RESULT_SENT = 'sent';

    public const RESULT_MARKED_PAID = 'marked_paid';

    public const RESULT_MARKED_FAILED = 'marked_failed';

    /** The line was no longer waiting for the bank, so the row was not applied. */
    public const RESULT_ALREADY_SETTLED = 'already_settled';

    /** The ADN is not in this batch. */
    public const RESULT_UNMATCHED = 'unmatched';

    public const RESULT_REJECTED_AMOUNT = 'rejected_amount';

    public const RESULT_REJECTED_UTR = 'rejected_utr';

    public const RESULT_UNRECOGNISED_STATUS = 'unrecognised_status';

    public $timestamps = false;

    protected $table = 'payout_bank_file_rows';

    protected $fillable = [
        'payout_bank_file_id', 'row_no', 'adn', 'payout_line_item_id', 'attempt', 'bank_status',
        'verdict', 'utr', 'amount_paise', 'reason', 'result',
    ];

    protected function casts(): array
    {
        return [
            'payout_bank_file_id' => 'integer',
            'row_no' => 'integer',
            'payout_line_item_id' => 'integer',
            'attempt' => 'integer',
            'amount_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<PayoutBankFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(PayoutBankFile::class, 'payout_bank_file_id');
    }
}
