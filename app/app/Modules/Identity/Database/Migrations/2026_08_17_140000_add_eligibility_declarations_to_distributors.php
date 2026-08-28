<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the three statutory eligibility declarations (C-08, R-53).
 *
 * T&C §2.3 requires a joiner to declare that they are of sound mind, are not
 * an undischarged insolvent, and have not been convicted of an offence
 * involving moral turpitude in the preceding five years. Until now the only
 * trace of any of this anywhere in the platform was a recital inside the
 * agreement text — "sound mind" appeared in no view, no column and no test —
 * so the question "which distributors declared this?" had no answer.
 *
 * Stored as three discrete facts rather than one "I accept the eligibility
 * criteria" flag. A single flag cannot tell you afterwards which limb was
 * false when one turns out to be, and these are the limbs that void the
 * agreement ab initio.
 *
 * Nullable because every existing distributor predates the question, and a
 * default of `true` would be the platform declaring something on their behalf
 * — the exact defect that made the orientation record worthless (R-50). Admin
 * can collect them retrospectively; until then the column says "never asked",
 * which is the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->boolean('declared_sound_mind')->nullable()->after('state');
            $table->boolean('declared_not_insolvent')->nullable()->after('declared_sound_mind');
            $table->boolean('declared_no_moral_turpitude')->nullable()->after('declared_not_insolvent');
            // One timestamp for the set: they are made together, in one
            // submission, and separate timestamps would imply they could be
            // made apart.
            $table->dateTime('declarations_made_at', 3)->nullable()->after('declared_no_moral_turpitude');
        });
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->dropColumn([
                'declared_sound_mind',
                'declared_not_insolvent',
                'declared_no_moral_turpitude',
                'declarations_made_at',
            ]);
        });
    }
};
