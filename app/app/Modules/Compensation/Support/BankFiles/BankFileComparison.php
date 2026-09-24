<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support\BankFiles;

use App\Modules\Compensation\Models\PayoutBankFile;

/**
 * What changed between two bank response files of the same batch, by ADN.
 */
final readonly class BankFileComparison
{
    /**
     * @param  list<BankFileRowChange>  $changes  ADNs that differ: changed first, then new, then missing — each by ADN
     * @param  array<string, int>  $counts  per kind: new, missing, status, utr, amount, reason
     * @param  list<string>  $duplicates  ADNs listed more than once in either file — compared on their first row
     */
    public function __construct(
        public PayoutBankFile $from,
        public PayoutBankFile $to,
        public array $changes,
        public array $counts,
        public int $unchanged,
        public array $duplicates,
    ) {}

    public function hasDifferences(): bool
    {
        return $this->changes !== [];
    }
}
