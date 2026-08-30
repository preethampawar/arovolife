<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor's application to open an Arete Development Centre.
 *
 * Statuses are plain strings (not a DB enum) so the state machine can grow
 * without an ALTER — and so the SQLite test database accepts the same
 * migration. The application snapshots the proposed centre (§A2–A3 of the
 * spec); on approval those values are copied onto the `arete_centers` row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arete_center_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            // Set on approval — the centre this application became.
            $table->foreignId('center_id')->nullable()->constrained('arete_centers')->nullOnDelete();
            $table->string('status', 20)->default('submitted');

            // §A2 centre identity
            $table->string('centre_name', 200);
            $table->string('contact_person', 150)->nullable();
            $table->string('alternate_contact_number', 20)->nullable();

            // §A3 premises
            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();
            $table->string('landmark', 150);
            $table->string('pincode', 6);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('property_type', 20);
            $table->unsignedInteger('premises_sqft');
            $table->decimal('distance_to_nearest_adc_km', 6, 1);
            $table->time('opening_time');
            $table->time('closing_time');
            $table->string('weekly_off', 10);

            // Review trail
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['distributor_id', 'status'], 'idx_aca_distributor_status');
            $table->index(['status', 'submitted_at'], 'idx_aca_status_submitted');
            $table->index('state', 'idx_aca_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arete_center_applications');
    }
};
