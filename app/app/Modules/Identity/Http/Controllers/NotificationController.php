<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        // `unread` declares no column: "not yet read" is a NULL test, not an
        // equality, so it is applied here rather than by ListFilters::apply().
        // It is applied to the morph relation itself, so it cannot reach a
        // notification addressed to anyone else.
        $filters = ListFilters::make($request, [
            FilterField::boolean('unread', 'Unread only'),
        ]);

        $notifications = $user->notifications()->latest();

        if ($filters->has('unread')) {
            $notifications->whereNull('read_at');
        }

        return view('notifications.index', [
            'filters' => $filters,
            'notifications' => $notifications->paginate(20)->withQueryString(),
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
