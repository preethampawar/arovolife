<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('registration_drafts');
    }

    public function down(): void
    {
        // No restoration — drafts are no longer used in the pure session-based flow.
    }
};
