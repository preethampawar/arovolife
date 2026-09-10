<?php

declare(strict_types=1);

/**
 * DPDP Act 2023 §4 / §8(3) — the retention promise in Privacy Notice §5 is
 * only worth what enforces it.
 *
 *   RET-01  a message past the window is purged; a recent one is not
 *   RET-02  a reported message is held back regardless of age
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function retUser(string $key): User
{
    return User::create([
        'full_name' => 'RET '.$key,
        'email' => 'ret-'.$key.'-'.uniqid().'@example.com',
        'phone_e164' => '+91955'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('ret-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
}

function retMessage(int $from, int $to, string $body, string $sentAt): Message
{
    $message = Message::create([
        'from_user_id' => $from,
        'to_user_id' => $to,
        'body' => $body,
        'read_at' => null,
    ]);

    // Written straight to the column: created_at is what the retention window
    // is measured against, and Eloquent would otherwise stamp it as now().
    Message::where('id', $message->id)->update(['created_at' => $sentAt]);

    return $message->refresh();
}

it('RET-01: purges a message past the window and leaves a recent one alone', function (): void {
    $alice = retUser('alice');
    $bob = retUser('bob');

    $old = retMessage($alice->id, $bob->id, 'Two years ago', now()->subMonths(25)->toDateTimeString());
    $recent = retMessage($alice->id, $bob->id, 'Last week', now()->subWeek()->toDateTimeString());

    $this->artisan('messages:purge')->assertExitCode(0);

    expect(Message::find($old->id))->toBeNull()
        ->and(Message::find($recent->id))->not->toBeNull();

    // Counts only — never the body, which is the PII the purge exists to remove.
    $audit = AuditLog::where('action', 'messaging.retention_purge')->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->details['messages_deleted'])->toBe(1);
});

it('RET-02: holds back a reported message regardless of age', function (): void {
    $alice = retUser('alice');
    $bob = retUser('bob');

    $reported = retMessage($alice->id, $bob->id, 'You will earn a lot', now()->subMonths(40)->toDateTimeString());

    MessageReport::create([
        'message_id' => $reported->id,
        'reported_by_user_id' => $bob->id,
        'category' => 'income_claim',
        'reason' => 'Promised earnings',
        'status' => MessageReport::STATUS_OPEN,
    ]);

    $this->artisan('messages:purge')->assertExitCode(0);

    // message_reports cascades on messages, so purging the message would
    // destroy the evidence the report exists to preserve.
    expect(Message::find($reported->id))->not->toBeNull()
        ->and(MessageReport::count())->toBe(1);

    $audit = AuditLog::where('action', 'messaging.retention_purge')->first();
    expect((int) $audit->details['messages_held_for_report'])->toBe(1);
});
