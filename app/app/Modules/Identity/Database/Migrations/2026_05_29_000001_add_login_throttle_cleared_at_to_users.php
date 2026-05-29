<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set by admin password-reset; consumed (and nulled) on the next
            // login attempt to clear any stale rate-limit lockout that built
            // up from the user trying their old password before the reset.
            $table->dateTime('login_throttle_cleared_at')->nullable()->after('password_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('login_throttle_cleared_at');
        });
    }
};
