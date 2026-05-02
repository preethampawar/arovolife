<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Closes the C-1 race: between step-4 dedup and step-10 finalise,
        // two concurrent registrations with the same PAN could both pass
        // the dedup query (no row locking) and both reach insert. The
        // unique index makes the second insert throw cleanly so the
        // controller can render a "PAN already registered" message rather
        // than a duplicate row in the table — Hard Rule #6.
        Schema::table('distributors', function (Blueprint $table) {
            $table->unique('pan_hash', 'uniq_distributors_pan_hash');
        });
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table) {
            $table->dropUnique('uniq_distributors_pan_hash');
        });
    }
};
