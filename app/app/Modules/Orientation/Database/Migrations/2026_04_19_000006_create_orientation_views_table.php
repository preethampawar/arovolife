<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orientation_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')
                ->index('idx_orientation_distributor')
                ->constrained('distributors')->cascadeOnDelete();
            $table->string('video_id', 64);
            $table->dateTime('started_at', 3);
            $table->dateTime('completed_at', 3)->nullable();
            $table->unsignedInteger('watch_percent')->default(0);
            $table->dateTime('quiz_passed_at', 3)->nullable();
            $table->string('playback_fingerprint', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orientation_views');
    }
};
