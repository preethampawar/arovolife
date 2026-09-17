<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Declarations recorded against a CENTRE, not against an application.
 *
 * `arete_center_application_declarations` is keyed on `application_id` with a
 * unique index on `(application_id, declaration_key)`. That is right for what
 * it does — it preserves exactly what an applicant agreed to, and a later
 * reword never rewrites it — but it makes two things impossible, both found
 * while ruling on R-21:
 *
 *   1. A new declaration version cannot be recorded against a centre that
 *      already exists. Bumping the version constant changes only what future
 *      applicants sign; it re-papers nobody.
 *   2. `AdminAreteCenterController::store()` creates a centre directly at
 *      STATUS_ACTIVE with no application at all, so those centres carry no
 *      declaration in any version.
 *
 * Both are R-95. This table is the fix: one row per (centre, declaration key,
 * version), so re-acceptance is expressible and a centre with no declaration is
 * visibly a centre with no rows.
 *
 * The backfill copies what applicants actually accepted, with their original
 * version, timestamp and IP. It invents nothing: a centre created by an admin
 * gets no rows, because no human ever accepted anything for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arete_center_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained('arete_centers')->cascadeOnDelete();
            $table->string('declaration_key', 40);
            $table->string('version', 10);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            // Who accepted. Null for a backfilled row: the original table
            // records the application, not the person, and guessing would be
            // worse than an honest gap.
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['center_id', 'declaration_key', 'version'], 'uniq_acdecl_centre_key_version');
            $table->index(['center_id', 'version'], 'idx_acdecl_centre_version');
        });

        // Backfill from the application-scoped table, where an application is
        // linked to a centre. Anything not linked has no centre to attach to.
        if (Schema::hasTable('arete_center_application_declarations') && Schema::hasTable('arete_center_applications')) {
            $rows = DB::table('arete_center_application_declarations as d')
                ->join('arete_center_applications as a', 'a.id', '=', 'd.application_id')
                ->whereNotNull('a.center_id')
                ->select('a.center_id', 'd.declaration_key', 'd.version', 'd.accepted_at', 'd.ip')
                ->get();

            $seen = [];
            foreach ($rows as $row) {
                $key = $row->center_id.'|'.$row->declaration_key.'|'.$row->version;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                DB::table('arete_center_declarations')->insert([
                    'center_id' => $row->center_id,
                    'declaration_key' => $row->declaration_key,
                    'version' => $row->version,
                    'accepted_at' => $row->accepted_at,
                    'ip' => $row->ip,
                    'accepted_by_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('arete_center_declarations');
    }
};
