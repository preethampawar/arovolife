<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Distributor-to-distributor direct messages.
 *
 *   GET  /messages              → conversation list (index)
 *   GET  /messages/{user}       → chat thread with $user (show)
 *   POST /messages/{user}       → send a message to $user (store)
 *
 * Authorization model (intentionally permissive for MVP):
 *   - Anyone authenticated can send to any user_id. The "Send Message"
 *     menu only appears on tree cards the sender can already see, so
 *     this self-limits in UX without server-side downline checks
 *     getting in the way of legitimate replies.
 *   - Anyone authenticated can read their own inbox (index) and any
 *     thread where they're one of the two parties.
 *
 * Phase 2 hardening targets: send rate limiting, block lists,
 * downline-only restriction for non-admin senders.
 */
final class MessageController extends Controller
{
    public function index(): View
    {
        $me = Auth::user();
        abort_if($me === null, 401);

        // Each conversation = the most-recent message in each (me, other)
        // pair, plus the per-pair unread count.
        //
        // Structured as derived-table → aggregate so the GROUP BY column
        // is a plain alias (other_user_id) rather than a CASE expression.
        // MySQL's ONLY_FULL_GROUP_BY mode otherwise refuses to recognise
        // that the CASE in SELECT matches the CASE in GROUP BY — even
        // though they're textually identical. SQLite (the test driver)
        // is permissive about this, so the previous form silently passed
        // tests but blew up on the real DB.
        $inner = DB::table('messages')
            ->selectRaw(
                'CASE WHEN from_user_id = ? THEN to_user_id ELSE from_user_id END AS other_user_id, '
                .'created_at, to_user_id, read_at',
                [$me->id],
            )
            ->where(function ($q) use ($me): void {
                $q->where('from_user_id', $me->id)->orWhere('to_user_id', $me->id);
            });

        $latestPerOther = DB::query()
            ->fromSub($inner, 't')
            ->selectRaw(
                'other_user_id, '
                .'MAX(created_at) AS last_at, '
                .'SUM(CASE WHEN to_user_id = ? AND read_at IS NULL THEN 1 ELSE 0 END) AS unread_count',
                [$me->id],
            )
            ->groupBy('other_user_id')
            ->orderByDesc('last_at')
            ->get();

        // Hydrate other-user names + the body of their latest message
        // for the preview cell. One IN-clause for the users, one
        // IN-clause for the latest messages.
        $otherUserIds = $latestPerOther->pluck('other_user_id')->map(fn ($v) => (int) $v)->all();
        $users = User::query()->whereIn('id', $otherUserIds)->get()->keyBy('id');

        // Each correspondent's ADN ("sender ID") for the inbox. Keyed by user_id
        // so the view can show the distributor number alongside the name —
        // never an email address.
        $adnByUser = DB::table('distributors')
            ->whereIn('user_id', $otherUserIds)
            ->pluck('adn', 'user_id');

        $previewByPair = [];
        foreach ($latestPerOther as $row) {
            $other = (int) $row->other_user_id;
            $previewByPair[$other] = Message::query()
                ->where(function ($q) use ($me, $other): void {
                    $q->where('from_user_id', $me->id)->where('to_user_id', $other);
                })
                ->orWhere(function ($q) use ($me, $other): void {
                    $q->where('from_user_id', $other)->where('to_user_id', $me->id);
                })
                ->orderByDesc('created_at')
                ->first();
        }

        return view('messages.index', [
            'conversations' => $latestPerOther,
            'users' => $users,
            'adnByUser' => $adnByUser,
            'previewByPair' => $previewByPair,
        ]);
    }

    public function show(Request $request, User $user, MessageService $service): View
    {
        $me = Auth::user();
        abort_if($me === null, 401);
        abort_if($me->id === $user->id, 422, 'You cannot open a chat with yourself.');

        $messages = Message::query()
            ->threadBetween($me->id, $user->id)
            ->with(['fromUser:id,full_name,email'])
            ->get();

        // Flip read_at on every message FROM $user TO $me. The bell badge
        // updates on the next request.
        $service->markThreadRead($me, $user);

        return view('messages.show', [
            'other' => $user,
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, User $user, MessageService $service): Response
    {
        $me = Auth::user();
        abort_if($me === null, 401);
        abort_if($me->id === $user->id, 422, 'You cannot message yourself.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = $service->send($me, $user, $data['body']);

        // JSON path drives the tree-view "Send Message" modal — the
        // person1 stays on the tree page; the modal shows a success
        // confirmation and auto-closes. HTML path is the chat view's
        // own compose form, which redirects back to the thread.
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message_id' => $message->id,
                'recipient' => [
                    'id' => $user->id,
                    'name' => $user->full_name ?: $user->email,
                ],
            ]);
        }

        return redirect()->route('messages.show', ['user' => $user->id]);
    }
}
