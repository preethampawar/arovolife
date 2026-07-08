<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifetime_award_milestones', function (Blueprint $table) {
            // D4 Part 1: track how many times the distributor has re-qualified for this rank.
            // Release thresholds: Ranks 1–2 = 1, Ranks 3–5 = 2, Ranks 6–9 = 3.
            $table->unsignedTinyInteger('qualification_count')->default(1)->after('triggered_month');

            // D4 Part 2: admin records disbursement method at delivery time.
            // goods = physical award (no admin charge / TDS).
            // cash  = cheque/bank transfer (Group C admin charge 3%/₹25k + 5% TDS).
            $table->enum('disbursement_type', ['goods', 'cash'])->nullable()->after('status');
            $table->unsignedBigInteger('gross_paise')->nullable()->after('disbursement_type');
            $table->unsignedBigInteger('admin_charge_paise')->default(0)->after('gross_paise');
            $table->unsignedBigInteger('tds_paise')->default(0)->after('admin_charge_paise');
            $table->unsignedBigInteger('net_paise')->nullable()->after('tds_paise');
        });
    }

    public function down(): void
    {
        Schema::table('lifetime_award_milestones', function (Blueprint $table) {
            $table->dropColumn([
                'qualification_count',
                'disbursement_type',
                'gross_paise',
                'admin_charge_paise',
                'tds_paise',
                'net_paise',
            ]);
        });
    }
};
