<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 255)->index('idx_contact_inquiries_email');
            $table->string('phone_e164', 20);
            $table->string('address', 500);
            $table->enum('purpose', [
                'become_distributor',
                'support',
                'compliance',
                'partnership',
                'other',
            ]);
            $table->text('message');
            $table->string('reason', 64)->nullable();   // optional banner-reason that opened the form
            $table->string('ip', 45);
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('handled_at', 3)->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('handled_at', 'idx_contact_inquiries_handled');
            $table->foreign('handled_by', 'fk_contact_inquiries_handled_by')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
