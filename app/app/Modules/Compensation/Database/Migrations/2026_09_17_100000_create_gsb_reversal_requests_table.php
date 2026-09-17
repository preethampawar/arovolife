<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The maker half of maker-checker on GSB reversals (R-92).
 *
 * Reversing a credit permanently removes commission earned against a product
 * sale: `computeForDistributor()` short-circuits a `reversed` row as already
 * settled, so nothing in the platform can put it back. Until 2026-09-17 one
 * holder of `compliance.discipline` could do that in a single click. This
 * table is the pending request that now stands between the click and the
 * ledger, so a second person signs the money off.
 *
 * The amounts are snapshotted at request time on purpose: the approver signs
 * off the figure the requester saw, and a mismatch against the live row at
 * approval time is a reason to refuse rather than something to paper over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsb_reversal_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gsb_cutoff_result_id')->nullable();
            $table->unsignedBigInteger('distributor_id');
            $table->date('cutoff_date');

            // What the requester saw. Compared against the live row at approval.
            $table->bigInteger('net_gsb_paise')->default(0);
            $table->bigInteger('repurchase_deduction_paise')->default(0);

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('reason');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_gsb_rev_req_status');
            $table->index(['distributor_id', 'status'], 'idx_gsb_rev_req_dist');

            // Nulled, not cascaded. The R-91 §14c production rebuild deletes
            // result rows by design, and a cascade there would silently destroy
            // the record of who asked for a reversal, who signed it and for how
            // much — the same reasoning as the two person columns below. The
            // snapshot, the reason and the decision survive with the row.
            $table->foreign('gsb_cutoff_result_id', 'fk_gsb_rev_req_result')
                ->references('id')->on('gsb_cutoff_results')->nullOnDelete();
            // The one FK here that still cascades, against the principle the
            // other two follow. A distributor row is never deleted in normal
            // operation — termination and dormancy are statuses — so this fires
            // only on a genuine erasure, where taking the reversal record with
            // it is the wanted outcome rather than a loss. Nulling it instead
            // would leave a row whose `distributor` accessor is typed non-null.
            $table->foreign('distributor_id', 'fk_gsb_rev_req_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();

            // Likewise the people: a staff account being removed must not
            // delete the record of who asked and who signed. The audit_log row
            // carries the actor id too.
            $table->foreign('requested_by', 'fk_gsb_rev_req_maker')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by', 'fk_gsb_rev_req_checker')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_reversal_requests');
    }
};
