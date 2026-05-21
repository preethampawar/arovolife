<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct messages between two users. One row per message; chat threads
 * are derived by selecting on (from, to) pairs in either direction.
 *
 * Indexes:
 *   - `idx_to_unread (to_user_id, read_at)` — the bell-icon unread-count
 *     query: `WHERE to_user_id = ? AND read_at IS NULL`.
 *   - `idx_thread_pair (from_user_id, to_user_id, created_at)` — fetching
 *     a thread between A and B requires two queries (A→B and B→A) joined
 *     by created_at; this index covers the A→B branch and a mirror
 *     query uses the table scan for B→A.
 *
 * FKs are CASCADE on user delete — a deleted user's message history is
 * removed along with the row. Acceptable for Phase 1 since users are
 * never hard-deleted in normal operation (status='terminated' is the
 * tombstone), and the trade-off of orphan-free message tables outweighs
 * the loss of an audit trail to a never-deleted user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->dateTime('read_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index(['to_user_id', 'read_at'], 'idx_to_unread');
            $table->index(['from_user_id', 'to_user_id', 'created_at'], 'idx_thread_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
