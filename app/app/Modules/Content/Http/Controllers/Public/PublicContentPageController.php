<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Public;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicContentPageController extends Controller
{
    public function show(string $slug): View
    {
        $page = ContentPage::where('slug', $slug)
            ->where('status', ContentPage::STATUS_PUBLISHED)
            ->first();

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return view('content.show', ['page' => $page]);
    }
}
