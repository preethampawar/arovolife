<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Public;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class PublicBlogController extends Controller
{
    public function index(): View
    {
        $pages = ContentPage::where('type', 'blog')
            ->where('status', ContentPage::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->get();

        return view('content.listing', [
            'pages' => $pages,
            'heading' => 'Blogs',
            'emptyMsg' => 'No blog posts yet.',
        ]);
    }
}
