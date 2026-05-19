<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['tnc', 'ethics', 'plan', 'privacy']);
            $table->string('version', 32);
            $table->binary('pdf_hash')->nullable();
            $table->dateTime('effective_from', 3);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->unique(['type', 'version'], 'uniq_agreements_type_version');

            $table->foreign('supersedes_id', 'fk_agreements_supersedes')
                ->references('id')->on('agreements')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE agreements MODIFY pdf_hash BINARY(32) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
