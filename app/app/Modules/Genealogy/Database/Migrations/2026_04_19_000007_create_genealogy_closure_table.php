<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genealogy_closure', function (Blueprint $table) {
            $table->unsignedBigInteger('ancestor_id');
            $table->unsignedBigInteger('descendant_id');
            $table->unsignedInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id', 'idx_closure_descendant');
            $table->index(['ancestor_id', 'depth'], 'idx_closure_anc_depth');

            $table->foreign('ancestor_id', 'fk_closure_ancestor')
                ->references('id')->on('distributors')->cascadeOnDelete();
            $table->foreign('descendant_id', 'fk_closure_descendant')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genealogy_closure');
    }
};
