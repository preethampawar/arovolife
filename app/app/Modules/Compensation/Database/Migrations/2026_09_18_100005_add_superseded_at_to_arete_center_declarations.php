<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A centre's declarations stop being evidence when the centre changes hands.
 *
 * The rows are keyed on `center_id`, so before this a centre transferred from
 * one distributor to another carried the first one's signature forward: the
 * dispatch gate would pass on evidence naming somebody who no longer ran the
 * premises. Worse, it made admin-on-behalf acceptance reachable in two steps —
 * staff accept for a company centre, an admin then assigns a distributor to it,
 * and that distributor receives parcels having signed nothing. That is exactly
 * what the two entry points in `AreteCenterDeclarationService` exist to prevent.
 *
 * Superseded rather than deleted, because "distributor A signed this on this
 * date" stays true after the centre moves to B, and destroying it would throw
 * away the only record of who undertook what while A ran the place. The gate
 * reads only live rows; an auditor can still read all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arete_center_declarations', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('accepted_at');
            $table->string('superseded_reason', 120)->nullable()->after('superseded_at');

            // The gate's read is "live rows for this centre at this version".
            $table->index(['center_id', 'version', 'superseded_at'], 'idx_acdecl_centre_version_live');
        });
    }

    public function down(): void
    {
        Schema::table('arete_center_declarations', function (Blueprint $table): void {
            $table->dropIndex('idx_acdecl_centre_version_live');
            $table->dropColumn(['superseded_at', 'superseded_reason']);
        });
    }
};
