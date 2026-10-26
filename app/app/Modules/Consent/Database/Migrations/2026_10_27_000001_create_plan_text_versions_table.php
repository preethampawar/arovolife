<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per compensation-plan text the change notice has announced (R-123).
 *
 * `notice_started_at` is written once, the first time the notice meets a hash
 * while the flag is on. Every notice clock runs from it — not from
 * `content_pages.updated_at`, which moves on any title or meta edit and on
 * every `content:publish`, even one that leaves the body unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_text_versions', function (Blueprint $table) {
            $table->id();
            $table->binary('doc_hash_sha256', length: 32, fixed: true)->unique('uniq_plan_text_versions_hash');
            $table->string('label', 32)->nullable();
            $table->dateTime('notice_started_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_text_versions');
    }
};
