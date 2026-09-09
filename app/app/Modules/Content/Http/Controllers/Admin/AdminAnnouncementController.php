<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Content\Models\Announcement;
use App\Modules\Content\Services\AnnouncementService;
use App\Modules\Content\Services\AnnouncementSettingsService;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AnnouncementsFeature;
use App\Modules\Shared\Rules\NoIncomeProjection;
use App\Modules\Shared\Rules\NoRawGovernmentId;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * Authoring company announcements.
 *
 * An announcement is the only copy on the platform that reaches every
 * distributor at once without passing through a code review, so the income
 * -projection audit that guards Blade templates is applied here at save time
 * instead. A draft is refused, not warned about: a warning on a page someone
 * is trying to publish is a warning someone clicks past.
 *
 * Publishing is a separate action from saving. The two-step exists so the
 * decision to send is deliberate — an admin who mistypes into the body of a
 * live announcement should be editing a draft, not broadcasting a keystroke.
 */
final class AdminAnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementSettingsService $settings) {}

    public function index(): View
    {
        $this->assertEnabled();

        return view('admin.announcements.index', [
            'announcements' => Announcement::query()
                ->with('author:id,full_name')
                ->withCount('reads')
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        $this->assertEnabled();

        return view('admin.announcements.form', [
            'announcement' => new Announcement(['audience' => Announcement::AUDIENCE_ALL]),
            'statuses' => $this->accountStatuses(),
        ]);
    }

    public function store(Request $request, AnnouncementService $service): RedirectResponse
    {
        $this->assertEnabled();

        $data = $this->validated($request);
        $this->assertPinnable($service, $data, null);

        $announcement = Announcement::query()->create($data + [
            'status' => Announcement::STATUS_DRAFT,
            'created_by_user_id' => Auth::id(),
        ]);

        $this->log('announcement.created', $announcement);

        return redirect()
            ->route('admin.announcements.edit', ['announcement' => $announcement->id])
            ->with('status', 'Draft saved. It is not visible to anyone until you publish it.');
    }

    public function edit(Announcement $announcement): View
    {
        $this->assertEnabled();

        return view('admin.announcements.form', [
            'announcement' => $announcement,
            'statuses' => $this->accountStatuses(),
        ]);
    }

    public function update(Request $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        $this->assertEnabled();

        $data = $this->validated($request);
        $this->assertPinnable($service, $data, $announcement);

        $announcement->update($data);

        $this->log('announcement.updated', $announcement);

        return back()->with('status', 'Saved.');
    }

    /**
     * Publish, archive, or return a published announcement to draft.
     *
     * Archiving is the closest thing to unsending there is, and it only
     * reaches the in-app copy: if the email switch was on when this went out,
     * that email is already in inboxes and nothing here recalls it.
     */
    public function transition(Request $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        $this->assertEnabled();

        $data = $request->validate([
            'status' => ['required', Rule::in([
                Announcement::STATUS_DRAFT,
                Announcement::STATUS_PUBLISHED,
                Announcement::STATUS_ARCHIVED,
            ])],
        ]);

        if ($data['status'] === Announcement::STATUS_PUBLISHED) {
            // Re-check the copy on the way out. A draft can be saved clean and
            // edited in another tab; the publish is the moment that matters.
            $offending = NoIncomeProjection::firstBannedPhrase($announcement->title.' '.$announcement->body);
            if ($offending !== null) {
                return back()->withErrors([
                    'body' => 'This announcement cannot be published: the phrase "'.$offending.'" implies a future income.',
                ]);
            }

            if ($announcement->pinned && ! $service->canPinAnother($announcement)) {
                return back()->withErrors(['pinned' => 'The pinned limit is already used. Unpin another announcement first.']);
            }
        }

        $before = (string) $announcement->status;

        $announcement->update([
            'status' => $data['status'],
            'published_at' => $data['status'] === Announcement::STATUS_PUBLISHED
                ? ($announcement->published_at ?? now())
                : $announcement->published_at,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'announcement.'.$data['status'],
            'subject_type' => 'announcement',
            'subject_id' => $announcement->id,
            'before_hash' => AuditLog::digest($before),
            'after_hash' => AuditLog::digest($data['status']),
            'details' => ['title' => $announcement->title, 'audience' => $announcement->audienceLabel()],
            'ip' => request()->ip(),
        ]);

        // Email only on the first publish. A republish after an archive would
        // otherwise mail the same announcement to the same people twice, and
        // an email is the one part of this that cannot be taken back.
        $emailed = null;
        if ($data['status'] === Announcement::STATUS_PUBLISHED
            && $before !== Announcement::STATUS_ARCHIVED
            && $this->settings->emailsCopies()) {
            $emailed = $service->emailAudience($announcement);
        }

        return back()->with('status', match ($data['status']) {
            Announcement::STATUS_PUBLISHED => $emailed === null
                ? 'Published. Every distributor in its audience can see it now.'
                : 'Published, and queued as an email to '.IndianNumber::format($emailed).' distributor(s).',
            Announcement::STATUS_ARCHIVED => 'Archived. It no longer appears for distributors.',
            default => 'Returned to draft.',
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200', new NoIncomeProjection],
            'body' => ['required', 'string', 'max:20000', new NoIncomeProjection, new NoRawGovernmentId],
            'audience' => ['required', Rule::in([
                Announcement::AUDIENCE_ALL,
                Announcement::AUDIENCE_STATUS,
                Announcement::AUDIENCE_RANK,
            ])],
            'audience_status' => ['nullable', Rule::in($this->accountStatuses())],
            'audience_rank' => ['nullable', 'integer', 'min:1', 'max:9'],
            'pinned' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        return [
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'audience_value' => match ($data['audience']) {
                Announcement::AUDIENCE_STATUS => $data['audience_status'] ?? null,
                Announcement::AUDIENCE_RANK => isset($data['audience_rank']) ? (string) $data['audience_rank'] : null,
                default => null,
            },
            'pinned' => (bool) ($data['pinned'] ?? false),
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPinnable(AnnouncementService $service, array $data, ?Announcement $announcement): void
    {
        if (($data['pinned'] ?? false) === true && ! $service->canPinAnother($announcement)) {
            abort(422, 'The pinned limit is already used. Unpin another announcement first.');
        }
    }

    private function log(string $action, Announcement $announcement): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'announcement',
            'subject_id' => $announcement->id,
            'details' => ['title' => $announcement->title, 'audience' => $announcement->audienceLabel()],
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Account states an announcement may be addressed to — read from the
     * canonical label map rather than listed here, so a new state cannot be
     * added to the platform and silently missing from this dropdown.
     *
     * @return list<string>
     */
    private function accountStatuses(): array
    {
        return array_keys(User::STATUS_LABELS);
    }

    private function assertEnabled(): void
    {
        abort_unless(Feature::for(null)->active(AnnouncementsFeature::class), 404);
    }
}
