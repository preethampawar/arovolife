<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support\BankFiles;

use App\Modules\Compensation\Models\PayoutBankFileRow;

/**
 * One ADN whose row differs between two bank response files.
 *
 * `$before` is the row in the earlier file and `$after` the row in the later
 * one; either is null when the ADN is missing from that file. `$kinds` names
 * what changed: new, missing, status, utr, amount, reason.
 */
final readonly class BankFileRowChange
{
    /** @param  list<string>  $kinds */
    public function __construct(
        public string $adn,
        public array $kinds,
        public ?PayoutBankFileRow $before,
        public ?PayoutBankFileRow $after,
    ) {}
}
