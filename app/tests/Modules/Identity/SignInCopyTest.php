<?php
// tests/Modules/Identity/SignInCopyTest.php
declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('shows Find my ADN and no primary-holder or remember-me controls', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();
    expect($html)->toContain('Find my ADN')
        ->not->toContain('Forgot your ADN')
        ->not->toContain('Primary account holder')
        ->not->toContain('name="remember"')
        ->not->toContain('coupleRoleRow');
});

it('titles registration step 9 My Personal Details', function () {
    $blade = file_get_contents(resource_path('views/registration/step3-personal.blade.php'));
    expect($blade)->toContain("'Step 9 — My Personal Details'")->toContain('>My Personal Details</h2>');
});
