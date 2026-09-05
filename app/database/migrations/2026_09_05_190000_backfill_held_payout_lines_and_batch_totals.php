<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Held payout lines (web_only / kyc_pending / no_bank_account /
 * bank_decrypt_failed) were written with repurchase_deduction_paise = 0 and
 * wallet_balance_paise = gross — true when repurchase was a payout-time
 * deduction, wrong since it moved to credit time (e315301): the deduction had
 * already left the main wallet. Batch totals also counted only paying lines,
 * so a batch of held income showed ₹0 gross on the list. Both are derived
 * figures; this recomputes them from the ledger and the line items as they
 * stood when each batch was processed.
 */
return new class extends Migration
{
    private const HELD = ['web_only', 'kyc_pending', 'no_bank_account', 'bank_decrypt_failed'];

    private const PAYING = ['pending', 'transferred', 'failed'];

    public function up(): void
    {
        $refTypesByBatchType = [
            'weekly' => ['gsb_cutoff_result'],
            'gsb_weekly' => ['gsb_cutoff_result'],
            'monthly' => ['gbb_monthly_result', 'rank_bonus_result', 'fortune_bonus_result'],
        ];

        $held = DB::table('payout_line_items as pli')
            ->join('payout_batches as pb', 'pb.id', '=', 'pli.payout_batch_id')
            ->whereIn('pli.status', self::HELD)
            ->where('pli.repurchase_deduction_paise', 0)
            ->select('pli.id', 'pli.distributor_id', 'pli.gross_paise', 'pb.id as batch_id', 'pb.batch_type', 'pb.processed_at', 'pb.created_at as batch_created_at')
            ->orderBy('pli.id')
            ->get();

        foreach ($held as $line) {
            $refTypes = $refTypesByBatchType[$line->batch_type] ?? null;
            if ($refTypes === null) {
                continue;
            }

            $asOf = $line->processed_at ?? $line->batch_created_at;

            // The transfers the line would have seen: created by the time the
            // batch ran and not yet swept by an earlier batch.
            $repurchase = abs((int) DB::table('wallet_ledger_entries')
                ->where('distributor_id', $line->distributor_id)
                ->where('type', 'repurchase_transfer')
                ->whereIn('reference_type', $refTypes)
                ->where('created_at', '<=', $asOf)
                ->where(function ($q) use ($line): void {
                    $q->whereNull('swept_by_payout_batch_id')
                        ->orWhere('swept_by_payout_batch_id', '>=', $line->batch_id);
                })
                ->sum('amount_paise'));

            if ($repurchase === 0) {
                continue;
            }

            DB::table('payout_line_items')->where('id', $line->id)->update([
                'repurchase_deduction_paise' => $repurchase,
                'wallet_balance_paise' => max(0, (int) $line->gross_paise - $repurchase),
            ]);
        }

        foreach (DB::table('payout_batches')->orderBy('id')->pluck('id') as $batchId) {
            $all = DB::table('payout_line_items')
                ->where('payout_batch_id', $batchId)
                ->selectRaw('COALESCE(SUM(gross_paise),0) AS gross, COALESCE(SUM(repurchase_deduction_paise + admin_charge_paise + tds_paise),0) AS deductions')
                ->first();

            $paying = DB::table('payout_line_items')
                ->where('payout_batch_id', $batchId)
                ->whereIn('status', self::PAYING)
                ->selectRaw('COALESCE(SUM(net_transferred_paise),0) AS net, COUNT(*) AS cnt')
                ->first();

            DB::table('payout_batches')->where('id', $batchId)->update([
                'total_gross_paise' => (int) $all->gross,
                'total_deductions_paise' => (int) $all->deductions,
                'total_net_paise' => (int) $paying->net,
                'distributor_count' => (int) $paying->cnt,
            ]);
        }
    }

    public function down(): void
    {
        // Derived figures recomputed from the ledger; nothing to restore.
    }
};
