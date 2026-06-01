<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // online = paid via gateway at checkout (cash received up front)
            // cod    = cash on delivery (cash received when the COD payment is
            //          collected; no prepayment ledger entry is posted at place)
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->enum('payment_method', ['online', 'cod'])->default('online')->after('attribution_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};
