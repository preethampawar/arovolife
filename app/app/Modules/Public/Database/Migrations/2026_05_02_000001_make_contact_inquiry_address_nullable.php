<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `contact_inquiries.address` optional. The contact form's UX changed
 * (postal address is rarely needed for an initial inquiry); the form-level
 * validation already allows nullable, this brings the schema in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->string('address', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->string('address', 500)->nullable(false)->change();
        });
    }
};
