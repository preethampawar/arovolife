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
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 128);
            $table->string('subject_type', 128);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->binary('before_hash')->nullable();
            $table->binary('after_hash')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 64)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['subject_type', 'subject_id'], 'idx_audit_subject');
            $table->index(['action', 'created_at'], 'idx_audit_action_time');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE audit_log MODIFY before_hash BINARY(32) NULL');
            DB::statement('ALTER TABLE audit_log MODIFY after_hash BINARY(32) NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
