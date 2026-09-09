<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Public;

use App\Modules\Content\Models\ContentPage;
use App\Modules\Content\Services\AnnouncementSettingsService;
use App\Modules\Shared\Features\FaqLibraryFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;

/**
 * The FAQ library — published `faq` content pages, grouped by category.
 *
 * It exists so that plan, payout and KYC questions have one audited company
 * answer. They are currently answered by uplines in direct messages, where
 * nothing scans the wording for an income claim; an entry here is a content
 * page, so it passes through the same publish workflow as the policy pages,
 * plus an income-projection check on the title and body that the untyped
 * policy pages do not have — they quote the banned phrases in order to forbid
 * them.
 *
 * Members-only by default (`faq.members_only`). A public FAQ is public copy:
 * every answer becomes a statement the company has made to prospects.
 */
final class PublicFaqController extends Controller
{
    public function __construct(private readonly AnnouncementSettingsService $settings) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(FaqLibraryFeature::class), 404);

        if ($this->settings->faqIsMembersOnly()) {
            abort_unless(Auth::check(), 404);
        }

        $search = trim((string) $request->query('q', ''));

        $entries = ContentPage::query()
            ->where('type', ContentPage::TYPE_FAQ)
            ->where('status', ContentPage::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->when($search !== '', function (Builder $query) use ($search): void {
                // A LIKE over title and body. Forty answers do not need an
                // index, let alone a search engine, and a wrong answer found
                // quickly is worse than the right one found by reading.
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('title', 'like', $term)->orWhere('body', 'like', $term);
                });
            })
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return view('faq.index', [
            'grouped' => $entries->groupBy(fn (ContentPage $page): string => $page->category ?: 'General'),
            'search' => $search,
            'total' => $entries->count(),
        ]);
    }
}
