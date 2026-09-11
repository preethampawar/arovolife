<?php

declare(strict_types=1);

use App\Modules\Commerce\Notifications\OrderNotificationChannels;
use App\Modules\Commerce\Notifications\OrderStatusChangedNotification;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function oscBuyer(): User
{
    return User::create([
        'full_name' => 'OSC Buyer',
        'email' => 'osc-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);
}

it('F59-01: an order status change is delivered by mail and as an in-app notification', function (): void {
    $notification = new OrderStatusChangedNotification('ORD-OSC-1', 'Buyer', 'Shipped');

    expect($notification->via(oscBuyer()))->toBe(['mail', 'database'])
        ->and(OrderNotificationChannels::default())->toBe(['mail']);
});

it('F59-02: the in-app record carries the order and its new status', function (): void {
    $user = oscBuyer();

    Notification::send($user, new OrderStatusChangedNotification('ORD-OSC-2', 'Buyer', 'Delivered'));

    $row = DB::table('notifications')->where('notifiable_id', $user->id)->first();
    expect($row)->not->toBeNull();

    $data = json_decode((string) $row->data, true);
    expect($data['kind'])->toBe('order.status_changed')
        ->and($data['order_no'])->toBe('ORD-OSC-2')
        ->and($data['status_label'])->toBe('Delivered');
});

it('F59-03: a guest buyer, who has no account to show it in, still gets the email', function (): void {
    Notification::fake();

    Notification::route('mail', 'guest-osc@test.com')
        ->notify(new OrderStatusChangedNotification('ORD-OSC-3', 'Guest', 'Shipped'));

    Notification::assertSentTo(new AnonymousNotifiable, OrderStatusChangedNotification::class);
    expect(DB::table('notifications')->count())->toBe(0);
});
