<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cooling-off refund is held until the returned goods are received
 * (terms §8: "within seven business days from receipt of the returned
 * product"). "Held" is a pair of nullable timestamps, not a new enum value:
 * `held_at` set and `released_at` null means held. No enum change, so the
 * SQLite test build and MySQL agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_intents', function (Blueprint $table): void {
            $table->string('mode', 8)->nullable()->after('gateway_refund_id');
            $table->string('speed', 16)->nullable()->after('mode');
            $table->dateTime('held_at', 3)->nullable()->after('speed');
            $table->string('hold_reason', 32)->nullable()->after('held_at');
            $table->dateTime('released_at', 3)->nullable()->after('hold_reason');
            $table->foreignId('released_by_user_id')->nullable()->after('released_at')->constrained('users')->nullOnDelete();
            $table->string('error_code', 64)->nullable()->after('released_by_user_id');
            $table->string('error_description', 255)->nullable()->after('error_code');
            $table->unsignedInteger('attempt_count')->default(0)->after('error_description');
            $table->dateTime('last_synced_at', 3)->nullable()->after('attempt_count');
            $table->dateTime('failed_at', 3)->nullable()->after('last_synced_at');
            $table->string('settled_via', 16)->nullable()->after('failed_at'); // gateway | manual_neft

            $table->index('status', 'idx_refund_intents_status');
            $table->index('gateway_refund_id', 'idx_refund_intents_gateway_refund');
        });
    }

    public function down(): void
    {
        Schema::table('refund_intents', function (Blueprint $table): void {
            $table->dropIndex('idx_refund_intents_status');
            $table->dropIndex('idx_refund_intents_gateway_refund');
            $table->dropConstrainedForeignId('released_by_user_id');
            $table->dropColumn([
                'mode', 'speed', 'held_at', 'hold_reason', 'released_at', 'error_code',
                'error_description', 'attempt_count', 'last_synced_at', 'failed_at', 'settled_via',
            ]);
        });
    }
};
