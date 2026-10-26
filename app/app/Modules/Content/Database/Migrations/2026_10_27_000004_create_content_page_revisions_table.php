<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A proposed change to the Compensation Plan page, waiting for a second
 * person to approve it (R-125).
 *
 * From 2026-10-26 the plan is changed in the admin editor rather than in code,
 * so the code review that used to stand between one person and the text every
 * distributor agrees to is gone. A plan edit is now saved here instead of on
 * the page, and goes live only when someone other than its author approves it.
 *
 * `base_digest` is the page (title, description, status, body) as the author
 * saw it. If the live page has moved on by approval, the approver would be
 * signing off a change against text nobody reviewed, so the approval is
 * refused instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_page_id')->constrained('content_pages')->cascadeOnDelete();
            $table->binary('base_digest', 32, fixed: true);

            $table->string('title', 200);
            $table->string('meta_description', 300)->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 20);

            $table->string('state', 20)->default('pending');
            $table->text('note');

            // Nulled, not cascaded: who proposed and who approved a plan
            // change is the record, and it outlives either account.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['content_page_id', 'state'], 'idx_cpr_page_state');
            $table->index(['state', 'created_at'], 'idx_cpr_state_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_page_revisions');
    }
};
