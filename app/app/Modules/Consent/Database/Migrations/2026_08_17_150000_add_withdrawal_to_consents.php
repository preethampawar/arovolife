<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes consent withdrawable (C-06, R-52).
 *
 * `privacy.md` §10.5 and the lawful-basis table both state that consent is
 * "revocable" and that a data principal "may withdraw it at any time". There
 * was no column, no route, no service and no UI — zero matches for consent
 * withdrawal anywhere in the codebase. DPDP 2023 §6(4)-(6) requires
 * withdrawal to be as easy as giving, and a published promise the platform
 * cannot honour is the same defect as the franchise collection-point picker
 * (R-47) and the half-price offer (R-49).
 *
 * The acceptance row is marked rather than deleted. Withdrawal does not
 * invalidate processing performed before it (§10.5), so the record of what
 * was agreed, and when, has to survive — otherwise the platform loses its
 * evidence of lawful processing for the period it was lawful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consents', function (Blueprint $table): void {
            $table->dateTime('withdrawn_at', 3)->nullable()->after('accepted_at');
            $table->string('withdrawal_reason', 255)->nullable()->after('withdrawn_at');

            // The queries that matter are "is this consent still live?" and
            // "who withdrew, and when?".
            $table->index(['distributor_id', 'withdrawn_at'], 'idx_consents_withdrawn');
        });
    }

    public function down(): void
    {
        Schema::table('consents', function (Blueprint $table): void {
            $table->dropIndex('idx_consents_withdrawn');
            $table->dropColumn(['withdrawn_at', 'withdrawal_reason']);
        });
    }
};
