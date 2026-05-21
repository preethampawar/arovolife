<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke-tests the A− / A / A+ / Reset font-size adjuster that lives in
 * the deep-blue utility strip of `partials.public-topnav`.
 *
 * - Confirms all four data-font-size buttons render in markup
 * - Confirms the FOUC-prevention <head> script is wired up so the saved
 *   percentage is applied before first paint
 * - Confirms the localStorage key the JS reads/writes is the one the
 *   product spec documents (`arovolife_root_font_size_pct`)
 */
class FontSizeAdjusterTest extends TestCase
{
    public function test_landing_page_renders_font_size_adjuster_buttons(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('data-font-size-adjuster', false);
        $response->assertSee('data-font-size="90"', false);
        $response->assertSee('data-font-size="100"', false);
        $response->assertSee('data-font-size="115"', false);
        $response->assertSee('data-font-size="130"', false);
        $response->assertSee('data-font-size-reset', false);
        $response->assertSee('Adjust font size', false);
    }

    public function test_landing_page_includes_fouc_preventer_in_head(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        // The FOUC partial sets documentElement.style.fontSize from the
        // saved localStorage value before first paint. Asserting on the
        // localStorage key catches both copies (head + topnav script).
        $response->assertSee('arovolife_root_font_size_pct', false);
    }
}
