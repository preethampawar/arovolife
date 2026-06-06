<?php

declare(strict_types=1);

/**
 * End-to-end tests for the messaging feature.
 *
 *   MSG-01..03  send → persist + email
 *   MSG-04      mark-thread-read flips read_at
 *   MSG-05..07  routes auth + view content
 *   MSG-08      bell badge query (unread count) is accurate
 */

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Notifications\NewMessageNotification;
use App\Modules\Messaging\Services\MessageService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function msgUser(string $key): User
{
    return User::create([
        'full_name' => 'MSG '.$key,
        'email' => 'msg-'.$key.'-'.uniqid().'@example.com',
        'phone_e164' => '+91955'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('msg-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
}

it('MSG-01: MessageService::send persists a row and dispatches the email notification', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    $message = app(MessageService::class)->send($alice, $bob, 'Hello Bob');

    expect($message->from_user_id)->toBe($alice->id);
    expect($message->to_user_id)->toBe($bob->id);
    expect($message->body)->toBe('Hello Bob');
    expect($message->read_at)->toBeNull();

    Notification::assertSentTo($bob, NewMessageNotification::class);
});

it('MSG-02: MessageService rejects empty / whitespace-only bodies', function (): void {
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    expect(fn () => app(MessageService::class)->send($alice, $bob, '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('MSG-04: markThreadRead flips read_at on every unread message FROM the other party', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');
    $service = app(MessageService::class);

    $service->send($alice, $bob, 'one');
    $service->send($alice, $bob, 'two');
    $service->send($bob, $alice, 'reply'); // direction reversed — should NOT be marked

    $flipped = $service->markThreadRead($bob, $alice);

    expect($flipped)->toBe(2);
    expect(Message::where('from_user_id', $alice->id)->whereNull('read_at')->count())->toBe(0);
    expect(Message::where('from_user_id', $bob->id)->whereNull('read_at')->count())->toBe(1);
});

it('MSG-05: GET /messages renders the conversation list for the authed user only', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');
    $carol = msgUser('carol');

    app(MessageService::class)->send($alice, $bob, 'Hey Bob');
    app(MessageService::class)->send($carol, $bob, 'Hey Bob from Carol');

    $response = $this->actingAs($bob->refresh())->get('/messages');
    $response->assertOk();
    $response->assertSee($alice->full_name);
    $response->assertSee($carol->full_name);
});

it('MSG-13: the inbox shows S.No, the sender ADN and a Reply link, and never the email', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    // Give Alice a distributor record so her ADN ("sender ID") is shown.
    disableTestForeignKeys();
    try {
        DB::table('distributors')->insert([
            'user_id' => $alice->id, 'adn' => 'ADN77777',
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    app(MessageService::class)->send($alice, $bob, 'Ledger looks good');

    $response = $this->actingAs($bob->refresh())->get('/messages');
    $response->assertOk()
        ->assertSee('S.No')
        ->assertSee($alice->full_name)
        ->assertSee('ADN77777')          // sender ID
        ->assertSee('Reply')             // reply button
        ->assertSee('Ledger looks good') // message
        ->assertDontSee($alice->email);  // no email leakage
});

it('MSG-06: GET /messages/{user} renders the chat with that user and marks unread as read', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    app(MessageService::class)->send($alice, $bob, 'Hi Bob');

    expect(Message::unreadFor($bob->id)->count())->toBe(1);

    $response = $this->actingAs($bob->refresh())->get('/messages/'.$alice->id);
    $response->assertOk();
    $response->assertSee('Hi Bob');

    expect(Message::unreadFor($bob->id)->count())->toBe(0);
});

it('MSG-07: POST /messages/{user} stores a message and redirects to the chat', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    $response = $this->actingAs($alice->refresh())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/messages/'.$bob->id, ['body' => 'Hey from Alice']);

    $response->assertRedirect('/messages/'.$bob->id);
    expect(Message::where('from_user_id', $alice->id)->where('to_user_id', $bob->id)->count())->toBe(1);
    Notification::assertSentTo($bob, NewMessageNotification::class);
});

it('MSG-08: unread-for scope underpins the bell badge — count is accurate', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');
    $service = app(MessageService::class);

    expect(Message::unreadFor($bob->id)->count())->toBe(0);

    $service->send($alice, $bob, 'first');
    $service->send($alice, $bob, 'second');
    expect(Message::unreadFor($bob->id)->count())->toBe(2);

    // Mark one as read manually
    Message::query()->where('to_user_id', $bob->id)->latest('id')->limit(1)->update(['read_at' => now()]);
    expect(Message::unreadFor($bob->id)->count())->toBe(1);
});

it('MSG-09: unauthenticated user cannot reach /messages', function (): void {
    $response = $this->get('/messages');
    $response->assertRedirect(route('login'));
});

it('MSG-11: AJAX POST returns JSON when Accept: application/json (tree-view modal path)', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    $response = $this->actingAs($alice->refresh())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->postJson('/messages/'.$bob->id, ['body' => 'Quick hello from the tree']);

    $response->assertOk();
    $response->assertJson([
        'ok' => true,
        'recipient' => ['id' => $bob->id],
    ]);
    expect(Message::where('from_user_id', $alice->id)->where('to_user_id', $bob->id)->count())->toBe(1);
    Notification::assertSentTo($bob, NewMessageNotification::class);
});

it('MSG-12: AJAX POST with empty body returns 422 + structured errors', function (): void {
    Notification::fake();
    $alice = msgUser('alice');
    $bob = msgUser('bob');

    $response = $this->actingAs($alice->refresh())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->postJson('/messages/'.$bob->id, ['body' => '']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['body']);
    expect(Message::count())->toBe(0);
    Notification::assertNothingSent();
});

it('MSG-10: user cannot message themselves (422)', function (): void {
    $alice = msgUser('alice');

    $response = $this->actingAs($alice->refresh())
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post('/messages/'.$alice->id, ['body' => 'talking to myself']);

    $response->assertStatus(422);
});
