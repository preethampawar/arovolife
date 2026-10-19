<?php

declare(strict_types=1);

use App\Modules\Identity\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Every mail is built from an HTML view only. A mail with no text/plain part is
 * a spam signal (MIME_HTML_ONLY), and a password reset that lands in spam is a
 * distributor locked out — so every outgoing message must carry one.
 */
it('sends the password reset with a readable plain-text part', function (): void {
    config(['mail.default' => 'array']);
    $url = 'https://arovolife.com/reset-password/abc123?email=a%40b.com';

    Notification::route('mail', 'a@b.com')->notifyNow(new PasswordResetNotification($url, 60));

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    $text = (string) $email->getTextBody();

    expect($email->getHtmlBody())->not->toBeNull()
        ->and($text)->toContain('Reset your password')
        ->and($text)->toContain('Reset my password: '.$url)
        ->and($text)->toContain('support@arovolife.com')
        ->and($text)->not->toContain('<')
        ->and($text)->not->toContain('word-break')
        ->and($text)->not->toContain('Use this link within 60 minutes');
});

it('does not touch a plain-text-only message', function (): void {
    config(['mail.default' => 'array']);

    Mail::raw('only text', fn ($m) => $m->to('a@b.com'));

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getTextBody())->toBe('only text')
        ->and($email->getHtmlBody())->toBeNull();
});
