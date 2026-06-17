<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * In-admin "Help & Reference" — renders curated markdown reference docs
 * (resources/help/) so the operations team can read them without leaving the
 * panel. The docs live inside the app so they're always present wherever it runs.
 *
 * Only the fixed, allow-listed entries below are ever read; no request input
 * reaches the filesystem, so there is no path-traversal surface.
 */
final class AdminHelpController extends Controller
{
    /**
     * Allow-listed reference docs. slug => metadata; `file` is the markdown
     * filename under resources/help/.
     *
     * @var array<string, array{title: string, description: string, file: string}>
     */
    private const DOCS = [
        'status-reference' => [
            'title' => 'Status Reference',
            'description' => 'Every status / lifecycle value across the platform — accounts, KYC, genealogy, catalog, orders, payments, returns and more — with plain-English explanations.',
            'file' => 'status-reference.md',
        ],
    ];

    public function index(): View
    {
        return view('admin.help.index', ['docs' => self::DOCS]);
    }

    public function show(string $slug): View
    {
        $doc = self::DOCS[$slug] ?? null;
        if ($doc === null) {
            throw new NotFoundHttpException;
        }

        $path = resource_path('help/'.$doc['file']);
        if (! is_file($path)) {
            throw new NotFoundHttpException;
        }

        // Trusted, repo-controlled markdown — strip any raw HTML defensively.
        $html = Str::markdown((string) file_get_contents($path), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('admin.help.show', [
            'slug' => $slug,
            'doc' => $doc,
            'docs' => self::DOCS,
            'html' => $html,
        ]);
    }
}
