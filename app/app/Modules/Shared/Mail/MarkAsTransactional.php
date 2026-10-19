<?php

declare(strict_types=1);

namespace App\Modules\Shared\Mail;

use Illuminate\Mail\Events\MessageSending;

/**
 * Every arovolife mail is transactional — OTPs, password resets, order and
 * cooling-off notices — and none is marketing. Elastic Email treats SMTP mail
 * as marketing unless told otherwise: it adds a one-click unsubscribe, and a
 * distributor who clicks it is suppressed from every later mail, OTPs
 * included. The IsTransactional header (Elastic's SMTP flag) stops that.
 */
final class MarkAsTransactional
{
    public function handle(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();

        if (! $headers->has('IsTransactional')) {
            $headers->addTextHeader('IsTransactional', 'True');
        }
    }
}
