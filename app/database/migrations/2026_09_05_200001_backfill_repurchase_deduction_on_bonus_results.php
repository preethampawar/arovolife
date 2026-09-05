<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring existing bonus result rows in line with the credit-time meaning of the
 * result tables:
 *
 *  - repurchase_deduction_paise = the `repurchase_deduction` ledger entries
 *    written against the row when it was credited (zero for rows credited
 *    before the credit-time system, whose deduction was taken on the payout
 *    line instead — historically accurate);
 *  - net = gross − repurchase deduction ("credited to wallet");
 *  - admin charge / TDS columns reset to zero. The payout used to write its
 *    apportioned admin charge and TDS back onto these rows; those figures are
 *    payout-time facts and live only on payout_line_items now.
 *
 * Runs as a PHP loop rather than a correlated-subquery UPDATE so it behaves
 * identically on MySQL and the SQLite test connection.
 */
return new class extends Migration
{
    /** @var array<string, array{table: string, gross: string, net: string|null, admin: string, tds: string, deduction: string|null}> */
    private const RESULT_TABLES = [
        'gsb_cutoff_result' => ['table' => 'gsb_cutoff_results', 'gross' => 'gross_gsb_paise', 'net' => 'net_gsb_paise', 'admin' => 'admin_charge_paise', 'tds' => 'tds_paise', 'deduction' => 'repurchase_deduction_paise'],
        'rank_bonus_result' => ['table' => 'rank_bonus_results', 'gross' => 'gross_paise', 'net' => 'net_paise', 'admin' => 'admin_charge_paise', 'tds' => 'tds_paise', 'deduction' => 'repurchase_deduction_paise'],
        'gbb_monthly_result' => ['table' => 'gbb_monthly_results', 'gross' => 'gbb_gross_paise', 'net' => 'gbb_net_paise', 'admin' => 'admin_charge_paise', 'tds' => 'tds_paise', 'deduction' => 'repurchase_deduction_paise'],
        'fortune_bonus_result' => ['table' => 'fortune_bonus_results', 'gross' => 'gross_paise', 'net' => 'net_paise', 'admin' => 'admin_charge_paise', 'tds' => 'tds_paise', 'deduction' => 'repurchase_deduction_paise'],
        // No repurchase deduction on these two; only the payout write-back is undone.
        // Mentorship has no stored net column (mb_paise was dropped 2026-06-30).
        'mentorship_bonus_result' => ['table' => 'mentorship_bonus_results', 'gross' => 'mb_gross_paise', 'net' => null, 'admin' => 'mb_admin_charge_paise', 'tds' => 'mb_tds_paise', 'deduction' => null],
        'adc_bonus_result' => ['table' => 'adc_bonus_results', 'gross' => 'gross_paise', 'net' => 'net_paise', 'admin' => 'admin_charge_paise', 'tds' => 'tds_paise', 'deduction' => null],
    ];

    public function up(): void
    {
        $touched = [];

        foreach (self::RESULT_TABLES as $referenceType => $c) {
            $touched[$c['table']] = 0;
            $deductionByRow = $c['deduction'] === null
                ? []
                : DB::table('wallet_ledger_entries')
                    ->where('type', 'repurchase_deduction')
                    ->where('reference_type', $referenceType)
                    ->whereNotNull('reference_id')
                    ->groupBy('reference_id')
                    ->selectRaw('reference_id, SUM(amount_paise) AS deduction')
                    ->pluck('deduction', 'reference_id')
                    ->map(fn ($v): int => abs((int) $v))
                    ->all();

            $netSelect = $c['net'] === null ? DB::raw('NULL as net') : $c['net'].' as net';

            DB::table($c['table'])
                ->select('id', $c['gross'].' as gross', $netSelect, $c['admin'].' as admin', $c['tds'].' as tds')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($c, $deductionByRow, &$touched): void {
                    foreach ($rows as $row) {
                        $deduction = $deductionByRow[(int) $row->id] ?? 0;
                        $net = max(0, (int) $row->gross - $deduction);

                        $netUnchanged = $c['net'] === null || (int) $row->net === $net;
                        if ($netUnchanged && (int) $row->admin === 0 && (int) $row->tds === 0
                            && ($c['deduction'] === null || $deduction === 0)) {
                            continue;
                        }

                        $update = [$c['admin'] => 0, $c['tds'] => 0];
                        if ($c['net'] !== null) {
                            $update[$c['net']] = $net;
                        }
                        if ($c['deduction'] !== null) {
                            $update[$c['deduction']] = $deduction;
                        }

                        DB::table($c['table'])->where('id', $row->id)->update($update);
                        $touched[$c['table']] = ($touched[$c['table']] ?? 0) + 1;
                    }
                });
        }

        // The per-row admin/TDS apportionment is gone for good (the authoritative
        // figures stay on payout_line_items and the payout ledger debits), so the
        // reset itself is recorded.
        if (array_sum($touched) > 0) {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.result_deductions.reset',
                'subject_type' => 'migration',
                'subject_id' => null,
                'details' => ['rows_updated' => $touched, 'migration' => basename(__FILE__)],
            ]);
        }
    }

    public function down(): void
    {
        // Data-only correction; the payout-time write-back it undoes no longer exists.
    }
};
