<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DB-atomic idempotency marker for PropagateGroupBvJob. The unique
        // order_id row is inserted inside the SAME transaction as the
        // group_bv_daily accumulation, replacing the old cache-based guard that
        // could double-count BV when a worker crashed between the DB commit and
        // the cache write.
        Schema::create('bv_propagation_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('distributor_id');
            $table->bigInteger('bv_paise');
            $table->date('date');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::drop('bv_propagation_log');
    }
};
