<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Exceptions\PlanChangeRefused;
use App\Modules\Content\Http\Requests\ContentPageRequest;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Content\Services\PlanChangeApprovals;
use App\Modules\Shared\Features\FaqLibraryFeature;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Mews\Purifier\Facades\Purifier;

final class AdminContentPageController extends Controller
{
    public function index(Request $request): View
    {
        $filters = ListFilters::make($request, [
            FilterField::text('q', 'Search', 'Title', columns: ['content_pages.title']),
            FilterField::select('status', 'Status', [
                ContentPage::STATUS_DRAFT => 'Draft',
                ContentPage::STATUS_PUBLISHED => 'Published',
                ContentPage::STATUS_ARCHIVED => 'Archived',
            ], column: 'content_pages.status', placeholder: 'All statuses'),
            FilterField::select('type', 'Type', $this->typeOptions(), column: 'content_pages.type', placeholder: 'All types'),
        ]);

        $pages = $filters->apply(ContentPage::query())->orderBy('title')->paginate(25)->withQueryString();

        return view('admin.content.index', ['pages' => $pages, 'filters' => $filters]);
    }

    /**
     * Mirrors {@see ContentPageRequest::allowedTypes()}: the editable types
     * plus faq while the library feature is on. Kept here rather than shared
     * because the filter only ever reads it, never validates against it.
     *
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        $types = ['blog' => 'Blog', 'seminar' => 'Seminar', 'news' => 'News', 'hub' => 'Hub'];

        if (Feature::for(null)->active(FaqLibraryFeature::class)) {
            $types[ContentPage::TYPE_FAQ] = 'FAQ';
        }

        return $types;
    }

    public function create(): View
    {
        return view('admin.content.create', [
            'page' => new ContentPage(['status' => ContentPage::STATUS_DRAFT]),
            'faqLibraryOn' => $this->faqLibraryOn(),
        ]);
    }

    public function store(ContentPageRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // The plan page comes from the seeder and changes only with a second
        // person's approval (R-125); creating it here would publish it alone.
        if ($data['slug'] === PlanChangeNotices::SLUG) {
            return back()->withInput()->withErrors(['slug' => 'The Compensation Plan page cannot be created here.']);
        }
        $data['body'] = $this->isPolicyPage($data['slug'])
            ? $this->purifyPolicy($data['body'] ?? '')
            : $this->purify($data['body'] ?? '');
        $data['updated_by_user_id'] = Auth::id();

        if ($data['status'] === ContentPage::STATUS_PUBLISHED) {
            $data['published_at'] = now();
        }

        if ($this->isPolicyPage($data['slug'])) {
            $data['edited_in_portal_at'] = now();
        }

        $page = ContentPage::create($data);

        $this->audit('content_page.created', $page);

        return redirect()
            ->route('admin.content.index')
            ->with('status', "Page \"{$page->title}\" created.");
    }

    public function edit(ContentPage $page, PlanChangeNotices $planChangeNotices, PlanChangeApprovals $approvals): View
    {
        $isPlanPage = PlanChangeApprovals::governs($page);

        return view('admin.content.edit', [
            'page' => $page,
            'faqLibraryOn' => $this->faqLibraryOn(),
            'policyPage' => $this->isPolicyPage($page->slug),
            'isPlanPage' => $isPlanPage,
            'planNoticeOn' => $planChangeNotices->enabled(),
            'pendingChange' => $isPlanPage ? $approvals->pending($page) : null,
        ]);
    }

    /**
     * Whether the editor offers `faq` as a type, and with it the category and
     * sort-order fields. Resolved on the pinned global scope, the same one the
     * admin console toggles, so the editor and ContentPageRequest agree about
     * what is offerable. While it is off the type is refused by validation
     * too — an option nobody can save is worse than no option at all.
     */
    private function faqLibraryOn(): bool
    {
        return Feature::for(null)->active(FaqLibraryFeature::class);
    }

    public function update(ContentPageRequest $request, ContentPage $page, PlanChangeApprovals $approvals): RedirectResponse
    {
        // Decided on the stored slug, never the submitted one.
        if (PlanChangeApprovals::governs($page)) {
            return $this->proposePlanChange($request, $page, $approvals);
        }

        $policyPage = $this->isPolicyPage($page->slug);
        $data = $request->validated();
        $data['body'] = $policyPage
            ? $this->policyBodyForUpdate($data['body'] ?? '', $page)
            : $this->purify($data['body'] ?? '');

        $previousStatus = (string) $page->status;
        $before = AuditDigests::snapshot($page);
        $wasPublished = $page->isPublished();
        $nowPublished = $data['status'] === ContentPage::STATUS_PUBLISHED;

        if (! $wasPublished && $nowPublished) {
            $data['published_at'] = now();
        }

        $page->fill($data);

        // The portal copy is now the master: content:publish, db:seed and
        // platform:reset leave this page alone from here on (R-125).
        if ($policyPage && $page->isDirty(['title', 'meta_description', 'body'])) {
            $page->edited_in_portal_at = now();
        }

        // A save that changes nothing leaves the row alone. A policy page's
        // updated_at is the version stamped on every consent to it
        // (ConsentDocuments), so re-saving the same text must not move it.
        if ($page->isDirty()) {
            $page->updated_by_user_id = (int) Auth::id();
            $page->save();
        }

        // Putting a page in front of the public, and taking it back down, are
        // not the same event as fixing a typo. Logging all three as
        // `content_page.updated` made the moment a policy page went live
        // indistinguishable from a copy edit in the audit trail.
        $action = match (true) {
            $previousStatus === $data['status'] => 'content_page.updated',
            $nowPublished => 'content_page.published',
            $data['status'] === ContentPage::STATUS_ARCHIVED => 'content_page.archived',
            default => 'content_page.updated',
        };

        $this->audit($action, $page, [
            'status_changed' => $previousStatus !== $data['status'],
            'previous_status' => $previousStatus,
        ], $before);

        return redirect()
            ->route('admin.content.edit', $page)
            ->with('status', 'Page saved.');
    }

    /**
     * A change to the Compensation Plan is not saved to the page: it waits
     * for a second person to approve it (R-125). The live page is untouched.
     */
    private function proposePlanChange(ContentPageRequest $request, ContentPage $page, PlanChangeApprovals $approvals): RedirectResponse
    {
        $data = $request->validated();
        $proposed = [
            'title' => (string) $data['title'],
            'meta_description' => $data['meta_description'] ?? null,
            'status' => (string) $data['status'],
            'body' => $this->policyBodyForUpdate($data['body'] ?? '', $page),
        ];

        if ($approvals->changedFields($page, $proposed) === []) {
            return redirect()->route('admin.content.edit', $page)->with('status', 'Nothing was changed, so nothing was saved.');
        }

        try {
            $revision = $approvals->request($page, $proposed, (string) $data['change_note'], (int) Auth::id());
        } catch (PlanChangeRefused $refused) {
            return back()->withInput()->withErrors(['plan_change' => $refused->getMessage()]);
        }

        return redirect()
            ->route('admin.content.plan-changes.show', $revision)
            ->with('status', 'Your change is saved and waiting for approval. The live page has not changed yet.');
    }

    public function destroy(ContentPage $page): RedirectResponse
    {
        if (PlanChangeApprovals::governs($page)) {
            return back()->with('error', 'The Compensation Plan is not archived from here. To take it down, save it as Archived with a note, and it waits for approval like any other plan change.');
        }

        $title = $page->title;
        $previousStatus = (string) $page->status;
        $before = AuditDigests::snapshot($page);
        $page->update([
            'status' => ContentPage::STATUS_ARCHIVED,
            'updated_by_user_id' => Auth::id(),
        ]);

        $this->audit('content_page.archived', $page, [
            'previous_status' => $previousStatus,
        ], $before);

        return redirect()
            ->route('admin.content.index')
            ->with('status', "Page \"{$title}\" archived.");
    }

    private function purify(string $dirty): string
    {
        return $dirty === '' ? '' : Purifier::clean($dirty);
    }

    /**
     * Is this one of the policy pages seeded from `database/seeders/content`?
     *
     * Those pages carry section headings and tables the default profile and
     * the Trix editor both flatten (R-125), so they are edited as raw HTML
     * and cleaned with the `policy` profile instead.
     */
    private function isPolicyPage(string $slug): bool
    {
        return in_array($slug, ContentPageSeeder::slugs(), true);
    }

    private function purifyPolicy(string $dirty): string
    {
        return $dirty === '' ? '' : Purifier::clean($dirty, 'policy');
    }

    /**
     * The body to store for a policy page: the stored text, untouched, when
     * the editor sent it back unchanged; otherwise the cleaned submission.
     *
     * A save that changes nothing must not change the text either: the plan
     * page's hash is what distributors agree to, so a cleaner that merely
     * re-serialised it would ask every one of them to agree again. Browsers
     * send textarea line breaks as CRLF, and the TrimStrings middleware drops
     * the trailing newline Str::markdown leaves, hence the normalisation.
     */
    private function policyBodyForUpdate(string $submitted, ContentPage $page): string
    {
        $submitted = str_replace("\r\n", "\n", $submitted);

        if (trim($submitted) === trim((string) $page->body)) {
            return (string) $page->body;
        }

        return $this->purifyPolicy($submitted);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>|null  $before  the page as it stood before
     *                                             this write; NULL on a create
     */
    private function audit(string $action, ContentPage $page, array $extra = [], ?array $before = null): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'content_page',
            'subject_id' => $page->id,
            // The whole page, not only its status: a body edit to a policy
            // page is exactly the thing a compliance review asks about.
            'before_hash' => AuditDigests::of($before),
            'after_hash' => AuditDigests::of($page),
            'details' => array_merge([
                'slug' => $page->slug,
                'title' => $page->title,
                'status' => $page->status,
            ], $extra),
        ]);
    }
}
