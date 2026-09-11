<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Content\Http\Requests\ContentPageRequest;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Shared\Features\FaqLibraryFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Mews\Purifier\Facades\Purifier;

final class AdminContentPageController extends Controller
{
    public function index(): View
    {
        $pages = ContentPage::orderBy('title')->paginate(25);

        return view('admin.content.index', ['pages' => $pages]);
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
        $data['body'] = $this->purify($data['body'] ?? '');
        $data['updated_by_user_id'] = Auth::id();

        if ($data['status'] === ContentPage::STATUS_PUBLISHED) {
            $data['published_at'] = now();
        }

        $page = ContentPage::create($data);

        $this->audit('content_page.created', $page);

        return redirect()
            ->route('admin.content.index')
            ->with('status', "Page \"{$page->title}\" created.");
    }

    public function edit(ContentPage $page): View
    {
        return view('admin.content.edit', [
            'page' => $page,
            'faqLibraryOn' => $this->faqLibraryOn(),
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

    public function update(ContentPageRequest $request, ContentPage $page): RedirectResponse
    {
        $data = $request->validated();
        $data['body'] = $this->purify($data['body'] ?? '');
        $data['updated_by_user_id'] = Auth::id();

        $previousStatus = (string) $page->status;
        $before = AuditDigests::snapshot($page);
        $wasPublished = $page->isPublished();
        $nowPublished = $data['status'] === ContentPage::STATUS_PUBLISHED;

        if (! $wasPublished && $nowPublished) {
            $data['published_at'] = now();
        }

        $page->update($data);

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

    public function destroy(ContentPage $page): RedirectResponse
    {
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
