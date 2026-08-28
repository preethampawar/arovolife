<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('distributor_id');
            $table->foreign('distributor_id')->references('id')->on('distributors')->onDelete('cascade');
            $table->unique('distributor_id');
            $table->enum('gender', ['male', 'female', 'transgender_other', 'prefer_not_to_say']);
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed', 'prefer_not_to_say']);
            $table->enum('highest_education', ['below_10th', '10th_pass', '12th_pass', 'diploma', 'graduate', 'post_graduate', 'doctorate', 'prefer_not_to_say']);
            $table->string('occupation', 191)->nullable();
            $table->string('mother_tongue', 100);
            $table->string('additional_language_1', 100)->nullable();
            $table->string('additional_language_2', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_profiles');
    }
};
