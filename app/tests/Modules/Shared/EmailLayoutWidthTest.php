<?php

declare(strict_types=1);

/**
 * The branded email shell is a fixed 600px table centred inside 12px of outer
 * padding, so it occupies 624px in total. A media query relaxes it to 100% on
 * narrow screens — but the breakpoint has to cover the OUTER width, not the
 * shell width. When it was written as `max-width: 600px`, every mail scrolled
 * sideways between 601px and 623px: the query had stopped applying while the
 * fixed shell still did not fit. That band is a tablet in portrait and a
 * narrow desktop reading pane, so it is a band real recipients sit in.
 */
function brandedLayout(): string
{
    return file_get_contents(resource_path('views/emails/layouts/branded.blade.php'));
}

it('relaxes the fixed shell before the viewport is narrower than the shell plus its padding', function (): void {
    $layout = brandedLayout();

    preg_match('/padding: 24px (\d+)px;/', $layout, $padding);
    preg_match('/max-width: (\d+)px; background-color: #ffffff/', $layout, $shell);
    preg_match('/\@media screen and \(max-width: (\d+)px\)/', $layout, $breakpoint);

    expect($padding)->not->toBeEmpty()
        ->and($shell)->not->toBeEmpty()
        ->and($breakpoint)->not->toBeEmpty();

    $outerWidth = (int) $shell[1] + (2 * (int) $padding[1]);

    expect((int) $breakpoint[1])->toBeGreaterThanOrEqual($outerWidth);
});

it('breaks a value that cannot fit rather than the layout', function (): void {
    // An ADN, an order number or a long email address has no space to wrap at,
    // so without this an auto-layout table widens past the shell to fit it.
    expect(brandedLayout())->toContain('word-break: break-word');
});

it('routes every branded email through the one layout that carries those rules', function (): void {
    $templates = glob(resource_path('views/emails/*.blade.php'));

    expect($templates)->not->toBeEmpty();

    $strays = array_values(array_map(
        fn (string $t): string => basename($t),
        array_filter(
            $templates,
            fn (string $t): bool => ! str_contains(file_get_contents($t), 'emails.layouts.branded'),
        ),
    ));

    expect($strays)->toBe([]);
});
