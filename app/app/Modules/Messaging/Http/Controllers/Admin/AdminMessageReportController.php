<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageReport;
use App\Modules\Shared\Features\MessagingFeature;
use App\Modules\Shared\Rules\NoRawGovernmentId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * The reported-messages worklist.
 *
 * This is the only surface on the platform where staff read a private
 * conversation, so two things are deliberate. Reviewers see the reported
 * message and the few messages around it for context — not the whole thread,
 * which would turn one complaint into a warrant to read a relationship. And
 * every open of a report is audit-logged, because a control that lets staff
 * read members' messages needs its own trail.
 *
 * Deciding a report never changes the message. Nothing here edits or deletes
 * what was said: the record of a mis-selling claim is the evidence for the
 * account action that follows, and an evidence store staff can edit is not
 * one a regulator would accept. Account consequences are applied with the
 * existing account tools on the distributor's page.
 */
final class AdminMessageReportController extends Controller
{
    /** Messages either side of the reported one, shown for context. */
    private const CONTEXT_WINDOW = 3;

    public function index(Request $request): View
    {
        $this->assertChannelOpen();

        $status = $request->query('status', MessageReport::STATUS_OPEN);
        $status = is_string($status) ? $status : MessageReport::STATUS_OPEN;

        $query = MessageReport::query()
            // The distributor rows carry the ADN and the id the admin
            // profile link needs: on a platform where several accounts share
            // a company name, a name alone does not tell a moderator who
            // reported whom.
            ->with([
                'reporter:id,full_name,email',
                'reporter.distributor:id,user_id,adn',
                'message.fromUser:id,full_name,email',
                'message.fromUser.distributor:id,user_id,adn',
                'reviewer:id,full_name',
            ])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('admin.messaging.reports.index', [
            'reports' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => MessageReport::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'categories' => MessageReport::CATEGORIES,
        ]);
    }

    public function show(MessageReport $report): View
    {
        $this->assertChannelOpen();

        $report->load([
            'reporter.distributor',
            'message.fromUser.distributor',
            'message.toUser.distributor',
            'reviewer',
        ]);
        $message = $report->message;

        // A control that lets staff read members' messages needs its own trail.
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'messaging.report_opened',
            'subject_type' => 'message_report',
            'subject_id' => $report->id,
            'details' => ['message_id' => (int) $report->message_id, 'category' => $report->category],
            'ip' => request()->ip(),
        ]);

        return view('admin.messaging.reports.show', [
            'report' => $report,
            'context' => $this->contextAround($message),
            'categories' => MessageReport::CATEGORIES,
        ]);
    }

    public function review(Request $request, MessageReport $report): RedirectResponse
    {
        $this->assertChannelOpen();

        $data = $request->validate([
            'status' => ['required', Rule::in([MessageReport::STATUS_REVIEWED, MessageReport::STATUS_ACTIONED])],
            'review_note' => ['required', 'string', 'max:2000', new NoRawGovernmentId],
        ]);

        $beforeStatus = (string) $report->status;

        $report->update([
            'status' => $data['status'],
            'review_note' => $data['review_note'],
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'messaging.report_reviewed',
            'subject_type' => 'message_report',
            'subject_id' => $report->id,
            'before_hash' => AuditLog::digest($beforeStatus),
            'after_hash' => AuditLog::digest($data['status']),
            'details' => ['message_id' => (int) $report->message_id, 'category' => $report->category],
            'ip' => request()->ip(),
        ]);

        return redirect()
            ->route('admin.messaging.reports.index')
            ->with('status', 'Report closed.');
    }

    /**
     * The reported message plus a few either side of it, oldest first.
     *
     * A single line is often unreadable on its own — "yes, guaranteed" means
     * nothing without the question it answers — but the whole history is more
     * than a reviewer needs to judge one complaint.
     *
     * @return Collection<int, Message>
     */
    private function contextAround(Message $message): Collection
    {
        // The pair predicate is nested rather than applied through the
        // threadBetween scope: that scope's top-level orWhere would bind
        // looser than the id comparison added after it, and the window would
        // silently include one side of the conversation in full.
        $pair = static function (Builder $query) use ($message): void {
            $a = (int) $message->from_user_id;
            $b = (int) $message->to_user_id;

            $query->where(function (Builder $q) use ($a, $b): void {
                $q->where('from_user_id', $a)->where('to_user_id', $b);
            })->orWhere(function (Builder $q) use ($a, $b): void {
                $q->where('from_user_id', $b)->where('to_user_id', $a);
            });
        };

        $before = Message::query()
            ->where($pair)
            ->where('id', '<', $message->id)
            ->orderByDesc('id')
            ->limit(self::CONTEXT_WINDOW)
            ->get();

        $after = Message::query()
            ->where($pair)
            ->where('id', '>', $message->id)
            ->orderBy('id')
            ->limit(self::CONTEXT_WINDOW)
            ->get();

        return $before->reverse()->push($message)->concat($after)->values();
    }

    private function assertChannelOpen(): void
    {
        abort_unless(Feature::for(null)->active(MessagingFeature::class), 404);
    }
}
