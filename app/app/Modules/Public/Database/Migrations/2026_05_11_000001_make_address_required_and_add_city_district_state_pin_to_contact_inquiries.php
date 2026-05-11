<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tighten the contact-inquiry schema so every submission carries a real
 * postal address (the earlier "make address optional" migration is
 * reverted here per product) plus the four classification fields the
 * support team uses to triage inbound mail: city, district, state, PIN.
 *
 * All five fields are NOT NULL going forward. Existing rows (created
 * while address was nullable) are stamped with a sentinel value so the
 * new constraint can apply without manual cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Stamp any rows that still have NULL address (legacy rows from
        // the period when address was optional) so the NOT NULL change
        // doesn't blow up. Same for the four new columns — we add them
        // nullable, backfill, then flip to NOT NULL.
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->string('city', 120)->nullable()->after('address');
            $table->string('district', 120)->nullable()->after('city');
            $table->char('state', 2)->nullable()->after('district');
            $table->char('pin_code', 6)->nullable()->after('state');
        });

        DB::table('contact_inquiries')->whereNull('address')->update(['address' => '(not provided)']);
        DB::table('contact_inquiries')->whereNull('city')->update(['city' => '(unknown)']);
        DB::table('contact_inquiries')->whereNull('district')->update(['district' => '(unknown)']);
        DB::table('contact_inquiries')->whereNull('state')->update(['state' => 'XX']);
        DB::table('contact_inquiries')->whereNull('pin_code')->update(['pin_code' => '000000']);

        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->string('address', 500)->nullable(false)->change();
            $table->string('city', 120)->nullable(false)->change();
            $table->string('district', 120)->nullable(false)->change();
            $table->char('state', 2)->nullable(false)->change();
            $table->char('pin_code', 6)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->string('address', 500)->nullable()->change();
            $table->dropColumn(['city', 'district', 'state', 'pin_code']);
        });
    }
};
