<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelled-order group BV reversal (KP Q8, 2026-06-27 + Round-2/3 answers):
 * no clawback of paid bonuses — instead the propagated group BV is reversed
 * from exactly the ancestors it was originally credited to, on the same side.
 * What today's (unsettled) accumulator can't absorb becomes a side-scoped
 * debt consumed by future propagation on that same side (negative-carry).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per-ancestor snapshot of every group BV credit, written in the same
        // transaction as the group_bv_daily accumulation. This is the single
        // source of "who was originally credited" — a reversal debits these
        // rows, never a re-derived upline (which a line change could alter).
        Schema::create('group_bv_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('ancestor_id');
            $table->string('side', 1);
            $table->unsignedBigInteger('bv_paise');               // actually credited (after debt consumption)
            $table->unsignedBigInteger('debt_consumed_paise')->default(0);
            $table->date('date');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'ancestor_id'], 'uniq_group_bv_credits');
            $table->index(['ancestor_id', 'date'], 'idx_group_bv_credits_anc_date');
        });

        // Outstanding side-scoped reversal debt: BV from a cancelled order that
        // the day's accumulator could not absorb. Consumed (before crediting)
        // by future propagation on the SAME side — "deduct from subsequent
        // purchases in that group" (KP Q8).
        Schema::create('group_bv_debts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->string('side', 1);
            $table->unsignedBigInteger('bv_paise');
            $table->timestamps();

            $table->unique(['distributor_id', 'side'], 'uniq_group_bv_debts');
            $table->foreign('distributor_id', 'fk_group_bv_debts_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });

        // Audit trail of each per-ancestor reversal: how much was netted off
        // the reversal day's accumulator vs pushed into forward debt.
        Schema::create('group_bv_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('ancestor_id');
            $table->string('side', 1);
            $table->unsignedBigInteger('bv_paise');
            $table->unsignedBigInteger('absorbed_paise')->default(0);
            $table->unsignedBigInteger('debt_paise')->default(0);
            $table->date('date');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'ancestor_id'], 'uniq_group_bv_reversals');
            $table->index(['ancestor_id', 'date'], 'idx_group_bv_reversals_anc_date');
        });

        Schema::table('bv_propagation_log', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('date');
        });

        // Backfill: snapshot per-ancestor credit rows for every order already
        // propagated, using the same closure join GroupBvAccumulatorService
        // used when it accumulated the BV (no debts existed before this).
        DB::table('group_bv_credits')->insertUsing(
            ['order_id', 'ancestor_id', 'side', 'bv_paise', 'date', 'created_at'],
            DB::table('bv_propagation_log as bpl')
                ->join('genealogy_closure as gc_anc', function ($join) {
                    $join->on('gc_anc.descendant_id', '=', 'bpl.distributor_id')
                        ->where('gc_anc.depth', '>', 0);
                })
                ->join('genealogy_closure as gc_child', function ($join) {
                    $join->on('gc_child.descendant_id', '=', 'gc_anc.descendant_id')
                        ->whereRaw('gc_child.depth = gc_anc.depth - 1');
                })
                ->join('distributors as dc', function ($join) {
                    $join->on('dc.id', '=', 'gc_child.ancestor_id')
                        ->on('dc.placement_parent_id', '=', 'gc_anc.ancestor_id');
                })
                ->whereIn('dc.placement_side', ['L', 'R'])
                ->select('bpl.order_id', 'gc_anc.ancestor_id', 'dc.placement_side', 'bpl.bv_paise', 'bpl.date', 'bpl.created_at'),
        );
    }

    public function down(): void
    {
        Schema::table('bv_propagation_log', function (Blueprint $table) {
            $table->dropColumn('reversed_at');
        });
        Schema::dropIfExists('group_bv_reversals');
        Schema::dropIfExists('group_bv_debts');
        Schema::dropIfExists('group_bv_credits');
    }
};
