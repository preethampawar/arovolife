<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an administrator last changed a repo-sourced policy page in the portal.
 *
 * From 2026-10-26 the plan page is edited in the portal, not in the repo
 * markdown (client decision, R-125). The policy pages are still seeded from
 * `database/seeders/content/*.md`, and `content:publish`, `db:seed` and
 * `platform:reset` all rewrite them from that source. A non-null value marks
 * the portal copy as the master: the seeder leaves the page alone unless the
 * overwrite is asked for by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->timestamp('edited_in_portal_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->dropColumn('edited_in_portal_at');
        });
    }
};
