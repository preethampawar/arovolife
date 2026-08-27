<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the T&C §21 auto-termination workflow.
 *
 * §21 gives a Direct Seller who has made no sale for twelve continuous months
 * a seven-day written notice before the account is terminated, and sets a
 * re-registration wait that depends on the rank they reached.
 *
 * The notice window is stored rather than derived because it is a legal
 * position: we must be able to show, years later, exactly when the notice was
 * issued and when it expired — not recompute it from whatever the settings say
 * at the time of the question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            // The seven-day §21 notice.
            $table->dateTime('inactivity_notice_at', 3)->nullable()->after('gsb_frozen_at');
            $table->dateTime('inactivity_notice_expires_at', 3)->nullable()->after('inactivity_notice_at');

            // Set when the account is terminated for any reason. `users.status`
            // already carries the terminal state; this carries the date, which
            // is what the re-registration clock counts from.
            $table->dateTime('terminated_at', 3)->nullable()->after('inactivity_notice_expires_at');
            $table->string('termination_reason', 255)->nullable()->after('terminated_at');

            // The date this PAN may hold a Direct Seller account again (§21).
            $table->date('reregistration_allowed_from')->nullable()->after('termination_reason');

            // Drives the nightly sweep: find live distributors whose notice has
            // expired, and those with no notice yet.
            $table->index(['status', 'inactivity_notice_expires_at'], 'idx_distributors_inactivity');
        });
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->dropIndex('idx_distributors_inactivity');
            $table->dropColumn([
                'inactivity_notice_at',
                'inactivity_notice_expires_at',
                'terminated_at',
                'termination_reason',
                'reregistration_allowed_from',
            ]);
        });
    }
};
