<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that make `content_pages` able to hold an FAQ library.
 *
 * The table already segments by `type` — blog, seminar, news, hub — each with
 * its own public listing. An FAQ entry is the same thing: a titled, bodied,
 * draft-then-published page owned by the content editor. What it needs beyond
 * the others is a grouping and an order, because a library of forty answers in
 * publication order is not a library.
 *
 * Both columns stay null for every existing type. Adding a table instead would
 * have meant a second editor, a second publish workflow and a second audit
 * trail for the same act — writing something for distributors to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->string('category', 80)->nullable()->after('type');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('category');

            // The FAQ listing: published entries of one type, grouped and ordered.
            $table->index(['type', 'category', 'sort_order'], 'idx_content_pages_faq');
        });
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table): void {
            $table->dropIndex('idx_content_pages_faq');
            $table->dropColumn(['category', 'sort_order']);
        });
    }
};
