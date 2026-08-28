<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Public;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class PublicSeminarController extends Controller
{
    public function index(): View
    {
        $pages = ContentPage::where('type', 'seminar')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->get();

        return view('content.listing', [
            'pages' => $pages,
            'heading' => 'Seminars',
            'emptyMsg' => 'No seminars scheduled yet.',
        ]);
    }
}
