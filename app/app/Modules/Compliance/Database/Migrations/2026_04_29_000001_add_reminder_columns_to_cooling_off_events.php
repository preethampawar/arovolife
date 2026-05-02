<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooling_off_events', function (Blueprint $table) {
            // Per-milestone idempotency: if the column is non-NULL, that
            // reminder was sent and must not fire again. The cron's WHERE
            // clauses use `<column> IS NULL AND ends_at - NOW() <= window`.
            $table->dateTime('reminder_d20_sent_at', 3)->nullable()->after('cancelled_at');
            $table->dateTime('reminder_d7_sent_at', 3)->nullable()->after('reminder_d20_sent_at');
            $table->dateTime('reminder_d1_sent_at', 3)->nullable()->after('reminder_d7_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('cooling_off_events', function (Blueprint $table) {
            $table->dropColumn(['reminder_d20_sent_at', 'reminder_d7_sent_at', 'reminder_d1_sent_at']);
        });
    }
};
