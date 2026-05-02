<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedBigInteger('from_sponsor_id');
            $table->unsignedBigInteger('to_sponsor_id');
            $table->dateTime('requested_at', 3);
            $table->dateTime('approved_at', 3)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->string('reason', 512)->nullable();

            $table->index('distributor_id', 'idx_line_change_distributor');

            $table->foreign('distributor_id', 'fk_lcr_distributor')
                ->references('id')->on('distributors')->cascadeOnDelete();
            $table->foreign('from_sponsor_id', 'fk_lcr_from')
                ->references('id')->on('distributors')->restrictOnDelete();
            $table->foreign('to_sponsor_id', 'fk_lcr_to')
                ->references('id')->on('distributors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_change_requests');
    }
};
