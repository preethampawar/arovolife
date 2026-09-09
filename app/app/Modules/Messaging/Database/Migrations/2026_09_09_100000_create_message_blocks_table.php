<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "A does not want to hear from B".
 *
 * Blocks are directional and personal: B blocking A says nothing about
 * whether A may hear from B. The unique pair is what makes a repeat block a
 * no-op rather than a duplicate row, so the block button is idempotent.
 *
 * A block never hides staff. Compliance has to be able to reach a distributor
 * about their own account, and a channel a member can close against the
 * company is not a channel the company can serve a notice on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['blocker_user_id', 'blocked_user_id'], 'uniq_message_block_pair');
            // The send guard's question: "has the recipient blocked the sender?"
            $table->index(['blocked_user_id', 'blocker_user_id'], 'idx_message_block_reverse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_blocks');
    }
};
