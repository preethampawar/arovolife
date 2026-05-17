<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_drafts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique('uniq_drafts_user')->constrained('users')->cascadeOnDelete();
            $table->binary('draft_token_hash')->nullable();
            $table->tinyInteger('current_step')->unsigned()->default(3);
            $table->unsignedBigInteger('sponsor_id');
            $table->unsignedBigInteger('placement_id');
            $table->enum('side_opt', ['L', 'R'])->nullable();
            $table->text('payload_enc');
            $table->dateTime('resume_link_sent_at', 3)->nullable();
            $table->dateTime('expires_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->index('expires_at', 'idx_drafts_expires');
        });

        // BLOB placeholder → fixed-width BINARY(32)
        DB::statement('ALTER TABLE registration_drafts MODIFY draft_token_hash BINARY(32) NOT NULL');
        DB::statement('ALTER TABLE registration_drafts ADD UNIQUE uniq_drafts_token (draft_token_hash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_drafts');
    }
};
