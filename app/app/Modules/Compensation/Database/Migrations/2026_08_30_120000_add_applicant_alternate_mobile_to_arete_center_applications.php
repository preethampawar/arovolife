<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's reference form (30-08-2026) carries an "Alternate Mobile No"
 * for the applicant, separate from the centre's own alternate contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arete_center_applications', function (Blueprint $table): void {
            $table->string('applicant_alternate_mobile', 20)->nullable()->after('alternate_contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('arete_center_applications', function (Blueprint $table): void {
            $table->dropColumn('applicant_alternate_mobile');
        });
    }
};
