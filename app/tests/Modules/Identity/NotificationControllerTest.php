<?php

declare(strict_types=1);

/**
 * The distributor's own in-app notification inbox (QA F59/F54 follow-up).
 *
 *   NC-01  the index lists a notification's title, body and time
 *   NC-02  opening one marks it read and redirects to its target url
 *   NC-03  a notification belonging to someone else 404s
 *   NC-04  the bell counts unread notifications and routes there when they
 *          are the only thing unread
 */

use App\Modules\Commerce\Notifications\OrderStatusChangedNotification;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function ncUser(string $key): User
{
    return User::create([
        'full_name' => 'NC '.$key,
        'email' => 'nc-'.$key.'-'.uniqid().'@example.com',
        'phone_e164' => '+91934'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => bcrypt('nc-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
}

it('NC-01: the index lists a notification\'s title, body and time', function (): void {
    $user = ncUser('index');
    Notification::send($user, new OrderStatusChangedNotification('ORD-NC-1', 'Buyer', 'Shipped'));

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Order update')
        ->assertSee('ORD-NC-1 is now Shipped.');
});

it('NC-02: opening one marks it read and redirects to its target url', function (): void {
    $user = ncUser('open');
    Notification::send($user, new OrderStatusChangedNotification('ORD-NC-2', 'Buyer', 'Delivered'));
    $notification = $user->notifications()->sole();
    expect($notification->read_at)->toBeNull();

    $this->actingAs($user)
        ->get(route('notifications.open', $notification->id))
        ->assertRedirect(url('/orders/ORD-NC-2'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('NC-03: a notification belonging to someone else 404s', function (): void {
    $owner = ncUser('owner');
    $stranger = ncUser('stranger');
    Notification::send($owner, new OrderStatusChangedNotification('ORD-NC-3', 'Buyer', 'Shipped'));
    $notification = $owner->notifications()->sole();

    $this->actingAs($stranger)
        ->get(route('notifications.open', $notification->id))
        ->assertNotFound();
});

it('NC-04: the bell counts unread notifications and routes there when they are the only thing unread', function (): void {
    $user = ncUser('bell');
    Notification::send($user, new OrderStatusChangedNotification('ORD-NC-4', 'Buyer', 'Shipped'));

    $this->actingAs($user);
    $bell = (string) view('partials._notification-bell')->render();

    expect($bell)->toContain('href="'.route('notifications.index').'"')
        ->and($bell)->toContain('Notifications (1 unread)')
        ->and($bell)->not->toContain('href="'.route('announcements.index').'"');
});
