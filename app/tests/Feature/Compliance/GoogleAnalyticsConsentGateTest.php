<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DPDP Act 2023 §5 requires free, informed, specific and unambiguous consent
 * before personal data (gtag's IP + device fingerprint) is collected. The GA
 * loader must therefore be fail-closed: nothing may contact googletagmanager
 * until the visitor has explicitly accepted.
 *
 * These assertions guard the two ways the gate can regress — the static
 * `<script async src=".../gtag/js">` tag coming back, and the consent key
 * disappearing (which would mean the banner is gone and GA loads blind).
 */
final class GoogleAnalyticsConsentGateTest extends TestCase
{
    // The landing page's shared top-nav composer queries product_categories,
    // so the schema must exist even though these are markup assertions.
    use RefreshDatabase;

    public function test_configured_analytics_id_ships_the_consent_gate_and_never_a_static_gtag_tag(): void
    {
        config(['arovolife.analytics.google_id' => 'G-TESTID123']);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('arovolife_ga_consent', false);
        $response->assertSee('ga-consent-banner', false);
        $response->assertDontSee('<script async src="https://www.googletagmanager.com/gtag/js', false);
    }

    public function test_no_analytics_id_emits_neither_the_gate_nor_gtag(): void
    {
        config(['arovolife.analytics.google_id' => null]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('arovolife_ga_consent', false);
        $response->assertDontSee('googletagmanager.com/gtag/js', false);
    }
}
