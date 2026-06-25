<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->date('batch_date')->unique('uniq_payout_batch_date');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->bigInteger('total_gross_paise')->default(0);
            $table->bigInteger('total_deductions_paise')->default(0);
            $table->bigInteger('total_net_paise')->default(0);
            $table->unsignedInteger('distributor_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
