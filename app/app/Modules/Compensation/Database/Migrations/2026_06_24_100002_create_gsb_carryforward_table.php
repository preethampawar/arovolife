<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsb_carryforward', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id')->unique('uniq_gsb_carryforward_dist');
            $table->bigInteger('power_side_bv_paise')->default(0);
            $table->enum('power_side', ['L', 'R'])->nullable();
            $table->bigInteger('slab1_weaker_bv_paise')->default(0);
            $table->timestamps();

            $table->foreign('distributor_id', 'fk_gsb_carryforward_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_carryforward');
    }
};
