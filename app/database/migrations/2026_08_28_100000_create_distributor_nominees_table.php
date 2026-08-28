<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_nominees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')
                ->unique()
                ->constrained('distributors')
                ->cascadeOnDelete();
            $table->string('full_name', 191);
            $table->enum('relationship', ['spouse', 'child', 'parent', 'sibling', 'other']);
            $table->date('date_of_birth');
            $table->string('pan_number', 20)->nullable();
            $table->char('aadhaar_last4', 4)->nullable();
            $table->text('aadhaar_encrypted')->nullable();
            $table->string('mobile', 15)->nullable();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->timestamp('consent_given_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_nominees');
    }
};
