<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * Seeds placeholder public content pages for the nav dropdowns (About, Arovo Hub)
 * and sample typed content (blog, news, seminar).
 *
 * All entries use firstOrCreate so re-running is strictly additive and safe.
 *
 * Compliance notes:
 *  - No income projections or earnings testimonials in any copy (Hard Rule #3, DSR 5(1)(d)).
 *  - "Success Story" body is scoped to the distributor journey experience, not monetary returns.
 */
final class PublicPagesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ── About dropdown pages — general (no type) ──────────────────────────
        ContentPage::firstOrCreate(
            ['slug' => 'management-team'],
            [
                'title' => 'Management Team',
                'body' => '<p>Meet our leadership team.</p>',
                'type' => null,
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        ContentPage::firstOrCreate(
            ['slug' => 'business-opportunities'],
            [
                'title' => 'Business Opportunities',
                'body' => '<p>Build lasting relationships and grow with a trusted Indian brand.</p>',
                'type' => null,
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        // COMPLIANCE: body must not contain income projections or earnings testimonials.
        ContentPage::firstOrCreate(
            ['slug' => 'success-story'],
            [
                'title' => 'Success Story',
                'body' => '<p>Our distributors share what the journey means to them.</p>',
                'type' => null,
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        // ── Arovo Hub pages — type = hub ─────────────────────────────────────
        ContentPage::firstOrCreate(
            ['slug' => 'health-services'],
            [
                'title' => 'Health Services',
                'body' => '<p>Wellness solutions for you and your family.</p>',
                'type' => 'hub',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        ContentPage::firstOrCreate(
            ['slug' => 'social-contribution'],
            [
                'title' => 'Social Contribution',
                'body' => '<p>Our commitment to community and the environment.</p>',
                'type' => 'hub',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        ContentPage::firstOrCreate(
            ['slug' => 'online-courses'],
            [
                'title' => 'Online Courses',
                'body' => '<p>Skill-building programs to support your journey.</p>',
                'type' => 'hub',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        // ── Sample typed content ──────────────────────────────────────────────
        ContentPage::firstOrCreate(
            ['slug' => 'welcome-blog'],
            [
                'title' => 'Welcome to Arovolife',
                'body' => '<p>An introduction to our platform and values.</p>',
                'type' => 'blog',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        ContentPage::firstOrCreate(
            ['slug' => 'platform-news'],
            [
                'title' => 'Platform Update',
                'body' => '<p>Important updates to our platform.</p>',
                'type' => 'news',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        ContentPage::firstOrCreate(
            ['slug' => 'upcoming-seminar'],
            [
                'title' => 'Upcoming Seminar',
                'body' => '<p>Join us for our next distributor seminar.</p>',
                'type' => 'seminar',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ],
        );

        $this->command->info('Seeded 9 public pages (About + Arovo Hub + sample typed content).');
    }
}
