<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-09 Lifetime Awards & Rewards: awards are merchandise only.
 * The cash disbursement path (2026_07_08_165243) is gone — no code reads or
 * writes disbursement_type, gross_paise, admin_charge_paise, tds_paise or
 * net_paise any more — so the five columns are dropped (fail-safe finding F-11).
 *
 * Schema-only, but the values are gone once dropped, so the audit row records
 * every milestone that carried a disbursement type or a gross figure (the list
 * when under 500 rows). It is written before the DDL, which MySQL commits
 * implicitly. down() re-adds the five columns nullable and empty.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.drop_lifetime_award_cash_columns';

    private const COLUMNS = ['disbursement_type', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_paise'];

    public function up(): void
    {
        $recorded = DB::table('lifetime_award_milestones')
            ->where(fn ($query) => $query->whereNotNull('disbursement_type')->orWhereNotNull('gross_paise'))
            ->orderBy('id')
            ->get(['id', 'disbursement_type', 'gross_paise', 'admin_charge_paise', 'tds_paise', 'net_paise'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'disbursement_type' => $row->disbursement_type !== null ? (string) $row->disbursement_type : null,
                'gross_paise' => $row->gross_paise !== null ? (int) $row->gross_paise : null,
                'admin_charge_paise' => (int) $row->admin_charge_paise,
                'tds_paise' => (int) $row->tds_paise,
                'net_paise' => $row->net_paise !== null ? (int) $row->net_paise : null,
            ])
            ->all();

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'lifetime_award_milestone',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_09_101000_drop_lifetime_award_cash_columns',
                'reason' => 'Client 2026-10-09: Lifetime Awards are merchandise only; the cash disbursement columns are dropped.',
                'columns_dropped' => self::COLUMNS,
                'rows_with_cash_values' => count($recorded),
                'rows' => count($recorded) < 500 ? $recorded : null,
            ],
        ]);

        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }

    public function down(): void
    {
        // Schema only: the columns come back nullable and empty. The values
        // they held are in the audit row's `rows` list.
        Schema::table('lifetime_award_milestones', function (Blueprint $table): void {
            $table->enum('disbursement_type', ['goods', 'cash'])->nullable()->after('status');
            $table->unsignedBigInteger('gross_paise')->nullable()->after('disbursement_type');
            $table->unsignedBigInteger('admin_charge_paise')->nullable()->after('gross_paise');
            $table->unsignedBigInteger('tds_paise')->nullable()->after('admin_charge_paise');
            $table->unsignedBigInteger('net_paise')->nullable()->after('tds_paise');
        });
    }
};
