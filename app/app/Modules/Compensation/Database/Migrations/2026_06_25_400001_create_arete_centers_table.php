<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arete_centers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->unsignedBigInteger('assigned_distributor_id');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->date('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('assigned_distributor_id');
            $table->foreign('assigned_distributor_id', 'fk_ac_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arete_centers');
    }
};
