<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who meets each rank's conditions so far this month, as of the last settled
 * day — the rank progress snapshot. One month only: every nightly snapshot
 * replaces the whole table.
 *
 * Read by the rank progress views alone. Never a rank: nothing that pays,
 * pools, grants, announces or terminates may read it (guarded by
 * RankProvisionalStandingsIsolationTest). Ranks are recorded only in
 * rank_qualifications, by the monthly check on the 1st.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_provisional_standings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            $table->unsignedTinyInteger('rank_number');
            $table->date('as_of_date');
            $table->timestamps();

            $table->unique(['distributor_id', 'month_start', 'rank_number'], 'uq_rank_prov_dist_month_rank');
            $table->index(['month_start', 'rank_number'], 'idx_rank_prov_month_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_provisional_standings');
    }
};
