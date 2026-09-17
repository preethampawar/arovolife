<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The collection code's verifier.
 *
 * This is NOT a second copy of `pod_hash_sha256`, which an earlier draft of the
 * plan was rightly pulled up on. They are different artefacts at different
 * times: this column is written when the parcel reaches the centre and is what
 * a typed code is checked against; `pod_hash_sha256` is written when the buyer
 * actually collects and is the receipt that it happened.
 *
 * Deliberately NOT `Shared\Otp\OtpService`, which the compliance review
 * suggested. That service is the right single source of truth for an OTP — it
 * hashes, caps attempts at five and compares in constant time — but it is
 * cache-backed with a ten-minute TTL, and a collection code has to survive
 * until the buyer walks into the centre, which may be days. On Redis under
 * `allkeys-lfu` a long-lived cache key can be evicted silently (ADR-0011), and
 * an evicted collection code is a buyer standing at a counter unable to take
 * their own parcel. So the code is stored here as an HMAC keyed on APP_KEY:
 * unforgeable by anyone with DB write, unrecoverable by anyone with DB read,
 * and it does not expire out from under the buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('handover_code_hash', 64)->nullable()->after('pod_hash_sha256');
            $table->unsignedSmallInteger('handover_attempts')->default(0)->after('handover_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['handover_code_hash', 'handover_attempts']);
        });
    }
};
