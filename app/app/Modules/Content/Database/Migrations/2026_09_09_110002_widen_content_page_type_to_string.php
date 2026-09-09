<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_pages.type` stops being an ENUM.
 *
 * It was created as ENUM('blog','seminar','news','hub') in August. Adding
 * 'faq' to an ENUM is one ALTER on MySQL and a whole-table rebuild on SQLite,
 * which is how a constraint ends up passing in dev and failing in tests — or
 * the reverse, which is worse. The allow-list belongs in the application
 * anyway: ContentPageRequest is the only place that can know whether the FAQ
 * library's flag is on, and therefore whether 'faq' is a legal type right now.
 *
 * Forward-only, and it widens rather than narrows, so every existing row stays
 * valid and no down() is offered that would fail on data it cannot represent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->string('type', 20)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed: narrowing back to the ENUM would fail on
        // any row that has since been given a type the ENUM never had.
    }
};
