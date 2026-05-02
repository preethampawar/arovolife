<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Content\Http\Requests\ContentPageRequest;
use App\Modules\Content\Models\ContentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
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
        return view('admin.content.create', ['page' => new ContentPage([
            'status' => ContentPage::STATUS_DRAFT,
        ])]);
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
        return view('admin.content.edit', ['page' => $page]);
    }

    public function update(ContentPageRequest $request, ContentPage $page): RedirectResponse
    {
        $data = $request->validated();
        $data['body'] = $this->purify($data['body'] ?? '');
        $data['updated_by_user_id'] = Auth::id();

        $wasPublished = $page->isPublished();
        $nowPublished = $data['status'] === ContentPage::STATUS_PUBLISHED;

        if (! $wasPublished && $nowPublished) {
            $data['published_at'] = now();
        }

        $page->update($data);

        $this->audit('content_page.updated', $page, [
            'status_changed' => $wasPublished !== $nowPublished,
        ]);

        return redirect()
            ->route('admin.content.edit', $page)
            ->with('status', 'Page saved.');
    }

    public function destroy(ContentPage $page): RedirectResponse
    {
        $title = $page->title;
        $page->update([
            'status' => ContentPage::STATUS_ARCHIVED,
            'updated_by_user_id' => Auth::id(),
        ]);

        $this->audit('content_page.archived', $page);

        return redirect()
            ->route('admin.content.index')
            ->with('status', "Page \"{$title}\" archived.");
    }

    private function purify(string $dirty): string
    {
        return $dirty === '' ? '' : Purifier::clean($dirty);
    }

    private function audit(string $action, ContentPage $page, array $extra = []): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'content_page',
            'subject_id' => $page->id,
            'details' => array_merge([
                'slug' => $page->slug,
                'title' => $page->title,
                'status' => $page->status,
            ], $extra),
        ]);
    }
}
