<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

/**
 * Single source of truth for the delivery channels of every order
 * notification. Mail only today.
 *
 * When the SMS gateway is integrated, add 'sms' here (and register the channel
 * driver + the notifiables' routeNotificationForSms()). Because every order
 * notification's via() reads this one method, SMS then lights up across all
 * order notifications with no further code changes — the "works seamlessly
 * after integration" guarantee.
 */
final class OrderNotificationChannels
{
    /** @return array<int, string> */
    public static function default(): array
    {
        return ['mail'];
    }
}
