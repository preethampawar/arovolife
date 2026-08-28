<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Notifications;

use App\Modules\Commerce\Notifications\OrderNotificationChannels;

/**
 * One place to decide how grievance notifications reach a complainant.
 *
 * Policy §2 and §3.1 promise the complaint number on screen and by email —
 * email only, because no SMS driver exists yet (PRD D-05). The published text
 * was amended to match rather than left claiming a channel we do not have.
 * When a vendor lands, adding 'sms' here keeps every grievance notification in
 * step at once, and §2/§3.1 can be amended back.
 *
 * Mirrors Commerce\Notifications\OrderNotificationChannels.
 *
 * @see OrderNotificationChannels
 */
final class GrievanceNotificationChannels
{
    /** @return array<int, string> */
    public static function default(): array
    {
        return ['mail'];
    }
}
