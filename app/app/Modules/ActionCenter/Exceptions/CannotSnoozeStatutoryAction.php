<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Exceptions;

/**
 * Statutory clocks are not a manager's to silence (plan §10.4): grievance
 * SLAs, the refund promise window, missing tax invoices and returns awaiting
 * receipt stay visible until they are actually resolved.
 */
final class CannotSnoozeStatutoryAction extends ActionCenterException
{
    public static function for(string $key): self
    {
        return new self("The action type [{$key}] is statutory and cannot be snoozed.");
    }
}
