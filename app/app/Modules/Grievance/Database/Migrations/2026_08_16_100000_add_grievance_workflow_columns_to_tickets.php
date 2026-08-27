<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the `tickets` table up to the commitments published at `/p/grievance`
 * (Grievance Redressal Policy v2026-08-16) and to DSR 2021 Rule 12.
 *
 * The original table assumed a 24h first response and a 7-day resolution. The
 * published policy is stricter in one place and looser in another: 48h
 * acknowledgement, 5 working days first substantive response, 30 days
 * resolution (60 where a third party is involved, with a status update every
 * 15 days). It also promises a four-step escalation matrix, anonymous
 * complaints, multi-channel intake and a 7-year retention clock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            // Intake — every channel in policy §3 routes into this one tracker.
            $table->string('channel', 20)->default('web')->after('severity');
            $table->string('reporter_name', 120)->nullable()->after('reporter_phone');
            $table->boolean('is_anonymous')->default(false)->after('reporter_name');

            // Acknowledgement is a separate, earlier promise than first response.
            $table->dateTime('sla_acknowledgement_at', 3)->nullable()->after('assigned_to_user_id');
            $table->dateTime('acknowledged_at', 3)->nullable()->after('sla_acknowledgement_at');

            // Escalation matrix (policy §4): 1 customer care, 2 grievance officer,
            // 3 nodal officer, 4 compliance committee.
            $table->unsignedTinyInteger('escalation_level')->default(1)->after('acknowledged_at');
            $table->dateTime('escalated_at', 3)->nullable()->after('escalation_level');

            // Third-party dependency extends resolution to 60 days and obliges a
            // status update every 15 days.
            $table->boolean('third_party_dependent')->default(false)->after('escalated_at');
            $table->dateTime('last_status_update_at', 3)->nullable()->after('third_party_dependent');

            // Breaches are recorded as immutable facts when first detected so the
            // monthly compliance report never has to recompute history.
            $table->dateTime('acknowledgement_breached_at', 3)->nullable()->after('last_status_update_at');
            $table->dateTime('first_response_breached_at', 3)->nullable()->after('acknowledgement_breached_at');
            $table->dateTime('resolution_breached_at', 3)->nullable()->after('first_response_breached_at');

            $table->text('resolution_note')->nullable()->after('resolved_at');
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();

            // DSR 2021 Rule 12 — 7 years from final closure.
            $table->date('retention_until')->nullable()->after('closed_by_user_id');

            $table->index(['status', 'escalation_level'], 'idx_tickets_status_escalation');
            $table->index('sla_acknowledgement_at', 'idx_tickets_sla_ack');
        });

        // Widen `tickets.category` to the seven grievance families published in
        // policy §5, and `ticket_events.kind` to the full timeline vocabulary.
        //
        // SQLite does NOT store enums as free strings, contrary to what an
        // earlier migration in this repo assumed: `$table->enum()` emits a
        // `varchar ... check (col in (...))`, and inserting a widened value
        // fails with "CHECK constraint failed". The tests run on SQLite, so the
        // widening has to happen on both drivers or the test suite diverges
        // from production in exactly the place that matters.
        //
        // MySQL keeps a real ENUM (the column is a documented vocabulary and
        // the DB should say so); SQLite drops to a plain string, since a
        // `->change()` there rebuilds the table anyway and the PHP enum casts
        // are what actually guard writes.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY COLUMN category ENUM('order','payment','refund','account','product','compliance','kyc','compensation','genealogy','ethics','privacy','platform','other') NOT NULL");
            DB::statement("ALTER TABLE ticket_events MODIFY COLUMN kind ENUM('status_change','comment','assignment','sla_breach','acknowledgement','escalation','attachment','notification','sla_extension','status_update','internal_note') NOT NULL");
        } else {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->string('category', 32)->change();
            });

            Schema::table('ticket_events', function (Blueprint $table): void {
                $table->string('kind', 32)->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ticket_events MODIFY COLUMN kind ENUM('status_change','comment','assignment','sla_breach') NOT NULL");
            DB::statement("ALTER TABLE tickets MODIFY COLUMN category ENUM('order','payment','refund','account','product','compliance','other') NOT NULL");
        }
        // On SQLite the widened columns stay plain strings — narrowing them back
        // would fail on any row already holding a new value.

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['closed_by_user_id']);
            $table->dropIndex('idx_tickets_status_escalation');
            $table->dropIndex('idx_tickets_sla_ack');
            $table->dropColumn([
                'channel', 'reporter_name', 'is_anonymous',
                'sla_acknowledgement_at', 'acknowledged_at',
                'escalation_level', 'escalated_at',
                'third_party_dependent', 'last_status_update_at',
                'acknowledgement_breached_at', 'first_response_breached_at', 'resolution_breached_at',
                'resolution_note', 'closed_by_user_id', 'retention_until',
            ]);
        });
    }
};
