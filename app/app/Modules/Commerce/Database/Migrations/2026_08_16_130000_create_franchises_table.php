<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Franchises — company-owned pickup / fulfilment points operated by
 * distributors.
 *
 * A franchise is fulfilment infrastructure, not a shop. Stock is company
 * consignment; sales stay online and ADN-attributed; the franchise owner
 * dispatches. That distinction is the whole basis on which the compliance
 * review permitted the feature (DC-01..DC-05, R-21..R-25), so two things
 * matter structurally and are enforced here:
 *
 *  - the **franchise code is not an ADN** and lives in its own column with its
 *    own format, so it can never be mistaken for one; and
 *  - a franchise has **no position in the Genos**. There is no parent, no
 *    side, no depth. It is a place, and places do not earn from a downline.
 *
 * The operating distributor is a plain FK. Their own Genos position is
 * untouched by operating one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchises', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique('uniq_franchises_code');
            $table->string('name', 160);

            // Null for the company's own primary franchise, which nobody
            // operates and which therefore earns nothing.
            $table->foreignId('operator_distributor_id')->nullable()
                ->constrained('distributors')->nullOnDelete();
            $table->boolean('is_company_primary')->default(false);

            $table->string('address_line', 255)->nullable();
            $table->string('pincode', 6)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('state', 100)->nullable();

            $table->enum('status', ['pending_approval', 'active', 'suspended', 'closed'])
                ->default('pending_approval');

            // Per-franchise override of `comp.franchise.rate_bp`. Null means
            // the plan rate. Kept per row because a franchise agreement is
            // signed individually and one may be onboarded on different terms.
            $table->unsignedSmallInteger('commission_rate_bp')->nullable();

            // A franchise and an Arete Development Center are different things
            // — one dispatches orders, the other develops a member base, and
            // they pay on different bases. They can be the same building, so
            // the link is recorded when it exists and never assumed.
            $table->foreignId('arete_center_id')->nullable()
                ->constrained('arete_centers')->nullOnDelete();

            $table->date('applied_at')->nullable();
            $table->date('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'pincode'], 'idx_franchises_status_pincode');
            $table->index('operator_distributor_id', 'idx_franchises_operator');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Which franchise the buyer chose to collect from, decided per
            // order at checkout (Product Owner, 2026-08-16) rather than fixed
            // at registration — so the registration wizard stays at 10 steps
            // and its compliance review is not reopened.
            $table->foreignId('franchise_id')->nullable()->after('attributed_distributor_id')
                ->constrained('franchises')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['franchise_id']);
            $table->dropColumn('franchise_id');
        });

        Schema::dropIfExists('franchises');
    }
};
