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
        Schema::table('users', function (Blueprint $table) {
            // Defaults to CURRENT_TIMESTAMP so any code path that creates a
            // user *without* explicitly passing this column gets an
            // activated account. The spouse-creation path in
            // RegistrationService::createSecondaryDistributor explicitly
            // passes NULL to gate login behind the activation magic-link
            // — that's the only flow that should produce an unactivated
            // user.
            $table->dateTime('password_set_at', 3)->nullable()->useCurrent()->after('password_hash');
        });

        // Backfill existing users — anyone created before this migration
        // chose their own password at registration, so stamp the column.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE users SET password_set_at = COALESCE(created_at, NOW()) WHERE password_set_at IS NULL');
        } else {
            // SQLite uses CURRENT_TIMESTAMP instead of NOW().
            DB::statement('UPDATE users SET password_set_at = COALESCE(created_at, CURRENT_TIMESTAMP) WHERE password_set_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
