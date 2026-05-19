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
        Schema::create('distributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique('uniq_distributors_user')->constrained('users')->restrictOnDelete();
            $table->string('adn', 16)->unique('uniq_distributors_adn');
            $table->binary('pan_hash')->nullable();
            $table->char('pan_last4', 4);
            $table->string('aadhaar_ref', 64)->nullable();
            $table->char('aadhaar_last4', 4)->nullable();
            $table->binary('bank_account_enc')->nullable();
            $table->char('bank_ifsc', 11);
            $table->unsignedBigInteger('sponsor_id');
            $table->unsignedBigInteger('placement_id_at_registration')->nullable();
            $table->unsignedBigInteger('placement_parent_id');
            $table->enum('placement_side', ['L', 'R'])->nullable();
            $table->enum('placement_strategy_snapshot', ['default_left', 'default_right', 'custom']);
            $table->enum('side_chosen_by', ['admin_default', 'sponsor_override', 'prospect_custom']);
            $table->unsignedInteger('depth');
            $table->dateTime('effective_date', 3);
            $table->dateTime('cooling_off_end_at', 3);
            $table->string('state', 64);
            $table->unsignedBigInteger('spouse_distributor_id')->nullable();
            $table->tinyInteger('is_primary_couple')->default(0);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['placement_parent_id', 'placement_side'], 'uniq_distributors_slot');
            $table->index('sponsor_id', 'idx_distributors_sponsor');
            $table->index('state', 'idx_distributors_state');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE distributors MODIFY pan_hash BINARY(32) NOT NULL');
            DB::statement('ALTER TABLE distributors MODIFY bank_account_enc VARBINARY(512) NOT NULL');
        }

        Schema::table('distributors', function (Blueprint $table) {
            $table->foreign('sponsor_id', 'fk_distributors_sponsor')
                ->references('id')->on('distributors')->restrictOnDelete();
            $table->foreign('placement_parent_id', 'fk_distributors_parent')
                ->references('id')->on('distributors')->restrictOnDelete();
            $table->foreign('spouse_distributor_id', 'fk_distributors_spouse')
                ->references('id')->on('distributors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table) {
            $table->dropForeign('fk_distributors_spouse');
            $table->dropForeign('fk_distributors_parent');
            $table->dropForeign('fk_distributors_sponsor');
            $table->dropForeign(['user_id']);
        });
        Schema::dropIfExists('distributors');
    }
};
