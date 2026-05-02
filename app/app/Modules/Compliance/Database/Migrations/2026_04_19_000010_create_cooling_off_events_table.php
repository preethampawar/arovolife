<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooling_off_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')
                ->unique('uniq_cooling_off_distributor')
                ->constrained('distributors')->cascadeOnDelete();
            $table->dateTime('opened_at', 3);
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->string('refund_trigger_event_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooling_off_events');
    }
};
