<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_bv_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('date');
            $table->bigInteger('left_bv_paise')->default(0);
            $table->bigInteger('right_bv_paise')->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['distributor_id', 'date'], 'uniq_group_bv_daily');
            $table->index('date', 'idx_group_bv_daily_date');
            $table->foreign('distributor_id', 'fk_group_bv_daily_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_bv_daily');
    }
};
