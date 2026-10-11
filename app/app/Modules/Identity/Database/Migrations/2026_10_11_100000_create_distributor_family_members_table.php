<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family members a distributor registers for the arovolife Family Card
 * (up to five). The name is PiiEncrypted ciphertext; `relation` is validated
 * in the application rather than a DB enum so a new relation needs no ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_family_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')
                ->constrained('distributors')
                ->cascadeOnDelete();
            $table->text('full_name');
            $table->string('relation', 20);
            $table->boolean('is_minor')->default(false);
            // Calendar year the child turns 18; the parent's consent lapses
            // after it and `family:expire-minor-consents` erases the row.
            $table->unsignedSmallInteger('turns_18_year')->nullable();
            $table->timestamp('consent_given_at');
            $table->timestamp('guardian_consent_at')->nullable();
            $table->string('consent_version', 20);
            // sha256 of the exact consent wording shown, so the record proves
            // which text was agreed to even after the copy changes.
            $table->char('consent_text_hash', 64);
            $table->foreignId('consented_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index('turns_18_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_family_members');
    }
};
