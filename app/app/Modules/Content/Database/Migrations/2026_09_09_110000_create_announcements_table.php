<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company announcements and who has read them.
 *
 * An announcement is company copy addressed to distributors, so it is stored
 * the way the policy pages are — drafted, published, archived, attributable to
 * the person who wrote it — rather than as a notification blob. What the
 * company said to its distributors, and when, is a record a regulator can ask
 * for; a broadcast with no draft state and no author is not one.
 *
 * Reads live in their own table rather than as a counter on the announcement.
 * The unread badge is then a join, and "who has actually seen this" survives
 * as a fact instead of being collapsed into a number that only goes up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            // 'all' | 'rank' | 'status'. audience_value carries the rank key or
            // the account status when the audience is narrowed.
            $table->string('audience', 20)->default('all');
            $table->string('audience_value', 40)->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            // Past this moment it stops appearing. Null means it stands until
            // someone archives it.
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The distributor-facing list: published, in date, newest first.
            $table->index(['status', 'published_at'], 'idx_announcements_published');
        });

        Schema::create('announcement_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['announcement_id', 'user_id'], 'uniq_announcement_read');
            // The bell's question: "what has this user not read?"
            $table->index(['user_id', 'announcement_id'], 'idx_announcement_reads_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
