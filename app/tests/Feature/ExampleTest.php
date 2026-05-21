<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * The hero now cross-fades text only; every slide is rendered in the
     * DOM simultaneously (just opacity 0 when inactive), so each slide's
     * headline must appear in the response body.
     */
    public function test_landing_page_renders_all_hero_slide_headlines(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Start Your Direct Selling Journey with', false);
        $response->assertSee('Quality Essentials', false);
        $response->assertSee('Your Trust,', false);
        // Marker proving the refactored stack structure is live.
        $response->assertSee('data-hero-stack', false);
    }
}
