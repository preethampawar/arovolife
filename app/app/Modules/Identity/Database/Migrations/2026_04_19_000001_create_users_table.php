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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique('uniq_users_email');
            $table->string('phone_e164', 16)->unique('uniq_users_phone');
            $table->string('password_hash', 255);
            $table->binary('mfa_secret_enc')->nullable();
            $table->dateTime('mfa_enabled_at', 3)->nullable();
            // 'rejected' was added in 2026_05_24 — listed here too so fresh
            // SQLite databases (used by the test suite) build a CHECK
            // constraint that allows it without needing the later ALTER.
            $table->enum('status', ['pending', 'active', 'frozen', 'terminated', 'rejected'])->default('pending');
            $table->dateTime('last_login_at', 3)->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->dateTime('email_verified_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY mfa_secret_enc VARBINARY(512) NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
