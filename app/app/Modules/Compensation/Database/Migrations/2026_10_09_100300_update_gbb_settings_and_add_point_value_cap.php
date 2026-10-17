<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client 2026-10-09: the Growth Booster pool drops from 5% to 4% of the
     * month's company BV, the per-distributor 120 AGP cap is retired, and the
     * point value is capped instead (default ₹240).
     *
     * Settings: the pool rate moves only while the row still holds the old
     * default (500), so an admin override is kept; the audit row records
     * `moved` either way so an environment still on 5% is visible. The AGP cap
     * row is deleted and its value recorded first.
     *
     * Schema: both the uncapped floored value and the cap in force are frozen on
     * the month's pool row so a later setting change never moves a frozen month.
     * Months frozen before this release keep NULL in both columns (they were
     * priced without a cap).
     */
    private const ACTION = 'plan.migration.update_gbb_settings_and_add_point_value_cap';

    private const POOL_RATE_KEY = 'comp.gbb.pool_rate_bp';

    private const AGP_CAP_KEY = 'comp.gbb.agp_cap';

    public function up(): void
    {
        DB::transaction(function (): void {
            $poolRateBefore = DB::table('settings')->where('key', self::POOL_RATE_KEY)->value('value');
            $moved = DB::table('settings')
                ->where('key', self::POOL_RATE_KEY)
                ->where('value', '500')
                ->update(['value' => '400', 'updated_at' => now()]) > 0;

            $agpCapBefore = DB::table('settings')->where('key', self::AGP_CAP_KEY)->value('value');
            $agpCapDeleted = DB::table('settings')->where('key', self::AGP_CAP_KEY)->delete() > 0;

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100300_update_gbb_settings_and_add_point_value_cap',
                    'reason' => 'Client 2026-10-09: Growth Booster pool 4%, point value capped at ₹240, per-distributor AGP cap retired.',
                    'pool_rate' => [
                        'key' => self::POOL_RATE_KEY,
                        'moved' => $moved,
                        'before' => $poolRateBefore,
                        'value' => $moved ? '400' : $poolRateBefore,
                    ],
                    'agp_cap' => [
                        'key' => self::AGP_CAP_KEY,
                        'existed' => $agpCapDeleted,
                        'value' => $agpCapBefore,
                    ],
                ],
            ]);
        });

        Schema::table('gbb_monthly_pools', function (Blueprint $table): void {
            $table->unsignedBigInteger('raw_point_value_paise')->nullable()->after('point_value_paise');
            $table->unsignedBigInteger('point_value_cap_paise')->nullable()->after('raw_point_value_paise');
        });
    }

    public function down(): void
    {
        // Never guess: the pool rate may have been an admin override that was
        // left alone, and the AGP cap row's value is only known from the audit
        // row. Restore both settings from it (pool_rate.before, agp_cap.value)
        // and drop gbb_monthly_pools.raw_point_value_paise and
        // point_value_cap_paise by hand.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(pool_rate.before is the prior comp.gbb.pool_rate_bp; agp_cap.value is the deleted comp.gbb.agp_cap).'
        );
    }
};
