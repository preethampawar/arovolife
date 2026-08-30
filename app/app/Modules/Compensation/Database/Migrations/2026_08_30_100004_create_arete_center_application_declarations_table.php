<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The five declarations an applicant accepts (§A5), one row per checkbox,
 * with the declaration text version, timestamp and IP — so a later change
 * to the wording never rewrites what was actually agreed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arete_center_application_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('arete_center_applications')->cascadeOnDelete();
            $table->string('declaration_key', 40);
            $table->string('version', 10);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'declaration_key'], 'uniq_acadecl_app_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arete_center_application_declarations');
    }
};
