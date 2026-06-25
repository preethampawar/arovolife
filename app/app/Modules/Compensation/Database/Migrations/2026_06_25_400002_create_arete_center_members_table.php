<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arete_center_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('distributor_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['center_id', 'distributor_id'], 'uniq_acm_center_dist');
            $table->foreign('center_id', 'fk_acm_center')->references('id')->on('arete_centers')->cascadeOnDelete();
            $table->foreign('distributor_id', 'fk_acm_dist')->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arete_center_members');
    }
};
