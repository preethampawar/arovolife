<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support\BankFiles;

use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBankFileRow;

/**
 * Every import of a batch side by side: one row per ADN, one column per
 * import file.
 */
final readonly class BankFileImportHistory
{
    /**
     * @param  list<PayoutBankFile>  $imports  oldest first
     * @param  array<string, array<int, PayoutBankFileRow|null>>  $rows  ADN => [import file id => its row, or null when absent]
     * @param  int  $totalAdns  every ADN seen in any import, before the differing-only filter
     */
    public function __construct(
        public array $imports,
        public array $rows,
        public int $totalAdns,
    ) {}
}
