<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Exceptions\MessageRefused;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageBlock;
use App\Modules\Messaging\Models\MessageReport;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessagingSettingsService;
use App\Modules\Shared\Features\MessagingFeature;
use App\Modules\Shared\Rules\NoRawGovernmentId;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Distributor-to-distributor direct messages.
 *
 *   GET    /messages                     → conversation list (index)
 *   GET    /messages/{user}              → chat thread with $user (show)
 *   POST   /messages/{user}              → send a message to $user (store)
 *   POST   /messages/{user}/block        → stop hearing from $user (block)
 *   DELETE /messages/{user}/block        → hear from them again (unblock)
 *   POST   /messages/report/{message}    → report a received message (report)
 *
 * Authorization model:
 *   - Who may send to whom, how often, and what a body may contain are
 *     policy decisions enforced in MessageService, not here. This controller
 *     translates a refusal into a 422; it never decides one. Adding a guard
 *     to this class instead of the service would leave the tree-card modal
 *     path unprotected.
 *   - Anyone authenticated can read their own inbox (index) and any
 *     thread where they're one of the two parties.
 */
final class MessageController extends Controller
{
    /**
     * Zero-trace gating: while the killswitch is off the whole surface 404s
     * rather than 403s, so nothing advertises that a closed channel exists.
     * for(null) pins the global scope the admin console toggles.
     */
    private function assertChannelOpen(): void
    {
        abort_unless(Feature::for(null)->active(MessagingFeature::class), 404);
    }

    public function index(): View
    {
        $this->assertChannelOpen();

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

    public function show(Request $request, User $user, MessageService $service, MessagingSettingsService $settings): View
    {
        $this->assertChannelOpen();

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
            'canMessage' => $service->canMessage($me, $user),
            'hasBlocked' => MessageBlock::blocks((int) $me->id, (int) $user->id),
            'blockingEnabled' => $settings->blockListEnabled() && ! $user->isStaff(),
            'reportingEnabled' => $settings->reportingEnabled(),
            'reportCategories' => MessageReport::CATEGORIES,
            'maxBodyChars' => $settings->maxBodyChars(),
        ]);
    }

    public function store(Request $request, User $user, MessageService $service, MessagingSettingsService $settings): Response
    {
        $this->assertChannelOpen();

        $me = Auth::user();
        abort_if($me === null, 401);
        abort_if($me->id === $user->id, 422, 'You cannot message yourself.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.$settings->maxBodyChars()],
        ]);

        // Every policy guard lives in the service, so both response shapes
        // report the same refusal in the same words.
        try {
            $message = $service->send($me, $user, $data['body']);
        } catch (MessageRefused $refused) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $refused->getMessage(),
                ], 422);
            }

            return back()
                ->withInput()
                ->withErrors(['body' => $refused->getMessage()]);
        }

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

    /**
     * Stop hearing from someone. Idempotent — a second click on a stale page
     * must not fail, so this is a firstOrCreate rather than an insert.
     *
     * Staff are not blockable: compliance has to be able to reach a
     * distributor about their own account.
     */
    public function block(User $user, MessagingSettingsService $settings): Response
    {
        $this->assertChannelOpen();

        $me = Auth::user();
        abort_if($me === null, 401);
        abort_unless($settings->blockListEnabled(), 404);
        abort_if($me->id === $user->id, 422, 'You cannot block yourself.');
        abort_if($user->isStaff(), 422, 'Company accounts cannot be blocked.');

        MessageBlock::query()->firstOrCreate([
            'blocker_user_id' => $me->id,
            'blocked_user_id' => $user->id,
        ]);

        return back()->with('status', 'You will no longer receive messages from this person.');
    }

    public function unblock(User $user, MessagingSettingsService $settings): Response
    {
        $this->assertChannelOpen();

        $me = Auth::user();
        abort_if($me === null, 401);
        abort_unless($settings->blockListEnabled(), 404);

        MessageBlock::query()
            ->where('blocker_user_id', $me->id)
            ->where('blocked_user_id', $user->id)
            ->delete();

        return back()->with('status', 'You will receive messages from this person again.');
    }

    /**
     * Report a message you received.
     *
     * Only the recipient may report: a sender reporting their own message
     * would be reporting themselves, and a third party cannot see the thread
     * at all. One report per person per message — the unique index makes a
     * second submission a no-op instead of a way to inflate the queue.
     */
    public function report(Request $request, Message $message, MessagingSettingsService $settings): Response
    {
        $this->assertChannelOpen();

        $me = Auth::user();
        abort_if($me === null, 401);
        abort_unless($settings->reportingEnabled(), 404);
        abort_unless((int) $message->to_user_id === (int) $me->id, 403);

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(MessageReport::CATEGORIES))],
            'reason' => ['nullable', 'string', 'max:2000', new NoRawGovernmentId],
        ]);

        MessageReport::query()->firstOrCreate(
            [
                'message_id' => $message->id,
                'reported_by_user_id' => $me->id,
            ],
            [
                'category' => $data['category'],
                'reason' => $data['reason'] ?? null,
                'status' => MessageReport::STATUS_OPEN,
            ],
        );

        return back()->with('status', 'Thank you — our team will review this message.');
    }
}
