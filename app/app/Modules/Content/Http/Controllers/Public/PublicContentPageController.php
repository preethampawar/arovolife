<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Public;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicContentPageController extends Controller
{
    /**
     * The generic published-page reader: policy pages, blog, news, seminars,
     * hub articles.
     *
     * FAQ entries are deliberately excluded. They are `content_pages` rows so
     * that they inherit the editor, the draft/publish workflow and the audit
     * trail — but their surface is `/faq`, which is gated by FaqLibraryFeature
     * and by `faq.members_only`. Serving them here as well would publish every
     * answer to the open internet regardless of either control, which is
     * exactly what those controls exist to prevent.
     */
    public function show(string $slug): View
    {
        $page = ContentPage::where('slug', $slug)
            ->where('status', ContentPage::STATUS_PUBLISHED)
            ->where(function ($query): void {
                $query->whereNull('type')->orWhere('type', '!=', ContentPage::TYPE_FAQ);
            })
            ->first();

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return view('content.show', ['page' => $page]);
    }
}
