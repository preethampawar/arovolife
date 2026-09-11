<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The distributor's own in-app notification inbox — the `database` channel
 * for events such as an order status change (QA F59). It rides the same
 * bell as messages and announcements (F54): a distributor has one place to
 * look for "something new for me".
 *
 * There is no "mark all as read" action, matching the announcements page —
 * opening one marks it read and, when it names its own destination, sends
 * the distributor there.
 */
final class NotificationController extends Controller
{
    public function index(): View
    {
        /** @var User $user */
        $user = Auth::user();

        return view('notifications.index', [
            'notifications' => $user->notifications()->latest()->paginate(20),
        ]);
    }

    public function open(string $id): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $notification = $user->notifications()->findOrFail($id);
        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $url = $notification->data['url'] ?? null;

        return $url !== null ? redirect()->to($url) : redirect()->route('notifications.index');
    }
}
