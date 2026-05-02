<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique('uniq_content_pages_slug');
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->dateTime('published_at', 3)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index('status', 'idx_content_pages_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
