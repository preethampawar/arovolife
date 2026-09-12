<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single table the Action Center owns (plan §3.2). There is no
 * `action_center_items` table: counts are live queries, so there is nothing to
 * sync and nothing to drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_center_snoozes', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->dateTime('snoozed_until', 3);
            // Required: a deferral with no stated reason is indistinguishable
            // from a queue quietly going unworked.
            $table->text('reason');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->nullable();
            $table->dateTime('updated_at', 3)->nullable();

            $table->unique(['action_key', 'subject_type', 'subject_id'], 'uniq_action_center_snoozes_subject');
            $table->index(['action_key', 'snoozed_until'], 'idx_action_center_snoozes_key_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_center_snoozes');
    }
};
