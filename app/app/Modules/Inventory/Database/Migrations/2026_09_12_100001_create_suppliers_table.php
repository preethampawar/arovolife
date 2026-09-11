<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('gstin', 15)->nullable();
            $table->string('contact_name', 100)->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('line1', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('status', 'idx_suppliers_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
