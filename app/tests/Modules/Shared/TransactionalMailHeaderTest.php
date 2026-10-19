<?php

declare(strict_types=1);

use App\Modules\Identity\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Elastic Email adds a one-click unsubscribe to any mail not flagged
 * transactional; a distributor who clicks it stops receiving OTPs and resets.
 */
it('flags a notification mail as transactional for Elastic Email', function (): void {
    config(['mail.default' => 'array']);

    Notification::route('mail', 'a@b.com')->notifyNow(new PasswordResetNotification('https://arovolife.com/reset-password/abc', 60));

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getHeaders()->get('IsTransactional')?->getBodyAsString())->toBe('True');
});

it('adds the header once even when the mail already carries it', function (): void {
    config(['mail.default' => 'array']);

    Mail::raw('only text', function ($m): void {
        $m->to('a@b.com');
        $m->getSymfonyMessage()->getHeaders()->addTextHeader('IsTransactional', 'True');
    });

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect(iterator_to_array($email->getHeaders()->all('istransactional'), false))->toHaveCount(1);
});
