<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            // Standard Laravel shape: email-as-PK, hashed token, timestamp.
            // Email is the natural key — at most one outstanding reset per
            // user. A new request overwrites the existing row, invalidating
            // the prior link.
            $table->string('email', 255)->primary();
            $table->string('token_hash', 64); // sha256 hex of the URL token
            $table->dateTime('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
