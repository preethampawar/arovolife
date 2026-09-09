<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor's report of a message they received.
 *
 * This table is the only route by which anything said in a private message
 * becomes visible to the company. The public copy audit scans Blade templates
 * for income projections; it cannot scan what one distributor types to
 * another. An upline promising earnings in a chat is a DSR Rule 5(1)(d)
 * breach the platform would otherwise never learn about — so the reporting
 * path is not a courtesy feature, it is the only evidence channel there is.
 *
 * The reported body is deliberately NOT copied here. The message row is the
 * record; duplicating it would create a second copy to redact if the message
 * turns out to contain the PAN or Aadhaar the send guard failed to catch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->constrained('users')->cascadeOnDelete();
            // 'income_claim' is first in the UI list on purpose — it is the
            // category the compliance team actually needs to hear about.
            $table->string('category', 40);
            $table->text('reason')->nullable();
            // open → reviewed (looked at, no action) | actioned (account touched)
            $table->string('status', 20)->default('open');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            // One report per person per message — a second click is a no-op,
            // not a way to inflate a queue.
            $table->unique(['message_id', 'reported_by_user_id'], 'uniq_message_report_reporter');
            $table->index(['status', 'created_at'], 'idx_message_reports_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reports');
    }
};
