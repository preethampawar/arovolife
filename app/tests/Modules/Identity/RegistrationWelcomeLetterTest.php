<?php

declare(strict_types=1);

/**
 * The registration email carries the welcome letter above the review status.
 *
 * RWL-01: the mail renders the welcome letter, the ADN, the free-joining note and the cooling-off line
 * RWL-02: the letter makes no earnings or generational-income claim (hard rule 3)
 * RWL-03: the standalone letter (admin-registered distributors) renders the letter, ADN and free-joining note
 */

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\RegistrationSubmittedNotification;
use App\Modules\Identity\Notifications\WelcomeLetterNotification;

function rwlHtml(): string
{
    $mail = (new RegistrationSubmittedNotification(adn: 'ARO1234567', fullName: 'Asha Rao'))
        ->toMail(new User);

    return (string) $mail->render();
}

it('RWL-01: renders the welcome letter with the ADN and the free-joining note', function (): void {
    $mail = (new RegistrationSubmittedNotification(adn: 'ARO1234567', fullName: 'Asha Rao'))->toMail(new User);
    $html = rwlHtml();

    expect($mail->subject)->toBe('Welcome to the arovolife family — your registration is being reviewed')
        ->and($html)->toContain('A warm welcome to the arovolife family')
        ->and($html)->toContain('Dear Asha Rao,')
        ->and($html)->toContain('What we stand for')
        ->and($html)->toContain('Best in Class, Best for Life.')
        ->and($html)->toContain('ARO1234567')
        ->and($html)->toContain('Registration is free of charge.')
        ->and($html)->toContain('30-day cooling-off period');
});

it('RWL-02: makes no earnings or generational-income claim', function (): void {
    expect(preg_match('/\\b(earn\\w*|income|legacy|financial growth|future generations)\\b/i', rwlHtml()))->toBe(0);
});

it('RWL-03: the standalone letter renders the letter, the ADN and the free-joining note', function (): void {
    $mail = (new WelcomeLetterNotification(adn: 'ARO7654321', fullName: 'Ravi Kumar'))->toMail(new User);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Welcome to the arovolife family')
        ->and($html)->toContain('Dear Ravi Kumar,')
        ->and($html)->toContain('What we stand for')
        ->and($html)->toContain('ARO7654321')
        ->and($html)->toContain('Registration is free of charge.')
        ->and($html)->toContain('30-day cooling-off period')
        ->and($html)->toContain('Consumer Protection (Direct Selling) Rules, 2021')
        ->and($html)->not->toContain('Your registration')
        ->and(preg_match('/\\b(earn\\w*|income|legacy|financial growth|future generations)\\b/i', $html))->toBe(0);
});
