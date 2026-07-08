<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            // Company-owned default centre has no distributor owner yet.
            $table->unsignedBigInteger('assigned_distributor_id')->nullable()->change();
            $table->boolean('is_company_default')->default(false)->after('notes');
            $table->index('is_company_default');
        });
    }

    public function down(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->dropIndex(['is_company_default']);
            $table->dropColumn('is_company_default');
            $table->unsignedBigInteger('assigned_distributor_id')->nullable(false)->change();
        });
    }
};
