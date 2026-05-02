<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sponsor_id');
            $table->unsignedBigInteger('distributor_id')->unique('uniq_sponsorship_distributor');
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index('sponsor_id', 'idx_sponsorship_sponsor');

            $table->foreign('sponsor_id', 'fk_sponsorship_sponsor')
                ->references('id')->on('distributors')->restrictOnDelete();
            $table->foreign('distributor_id', 'fk_sponsorship_distributor')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship');
    }
};
